<?php

namespace Tests\Feature;

use App\Models\Alerte;
use App\Models\AlerteDestinataire;
use App\Models\Complice;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrajetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: User, 2: User, 3: Complice, 4: Complice} user + 2 complices acceptés */
    private function cercle(): array
    {
        $user = User::factory()->create(['nom' => 'Awa']);
        $p1 = User::factory()->create();
        $p2 = User::factory()->create();
        $c = [];
        foreach ([$p1, $p2] as $i => $p) {
            $comp = new Complice(['nom' => 'Proche '.($i + 1), 'telephone' => $p->telephone, 'ordre' => $i + 1]);
            $comp->user_id = $user->id;
            $comp->complice_user_id = $p->id;
            $comp->statut = 'accepte';
            $comp->invitation_token = str_repeat((string) ($i + 1), 48);
            $comp->save();
            $c[] = $comp;
        }

        return [$user, $p1, $p2, $c[0], $c[1]];
    }

    public function test_demarrage_validation_et_un_seul_trajet(): void
    {
        [$user, , , $c1] = $this->cercle();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/trajets', ['duree_minutes' => 10])->assertStatus(422)->assertJsonValidationErrors('duree_minutes');
        $this->postJson('/api/v1/trajets', ['duree_minutes' => 181])->assertStatus(422);
        $this->postJson('/api/v1/trajets', ['duree_minutes' => 30, 'complice_ids' => [99999]])->assertStatus(422);

        $this->getJson('/api/v1/trajets/en-cours')->assertOk()->assertJsonPath('data', null);
        $this->postJson('/api/v1/trajets', ['duree_minutes' => 30, 'complice_ids' => [$c1->id], 'lat' => 5.36, 'lng' => -4.0, 'partage_position' => true])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'en_cours')
            ->assertJsonCount(1, 'data.complices');
        $this->postJson('/api/v1/trajets', ['duree_minutes' => 30])->assertStatus(409);
        $this->getJson('/api/v1/trajets/en-cours')->assertJsonPath('data.statut', 'en_cours');
    }

    public function test_arrivee_avant_l_echeance_annule_tout(): void
    {
        Carbon::setTestNow('2026-11-12 20:00:00');
        [$user] = $this->cercle();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/trajets', ['duree_minutes' => 15])->assertCreated()->json('data.id');

        Carbon::setTestNow('2026-11-12 20:10:00');
        $this->postJson("/api/v1/trajets/{$id}/arrive")->assertOk()->assertJsonPath('data.statut', 'arrive');

        Carbon::setTestNow('2026-11-12 20:30:00');
        $this->artisan('bravido:verifier-trajets')->assertSuccessful();
        $this->assertSame(0, Alerte::count());
        $this->assertSame('arrive', Trajet::find($id)->statut);
        $this->getJson('/api/v1/trajets/en-cours')->assertJsonPath('data', null);
    }

    public function test_rappel_a_l_echeance_puis_alerte_apres_la_grace_vers_les_complices_choisis(): void
    {
        Carbon::setTestNow('2026-11-12 20:00:00');
        [$user, $p1, $p2, $c1] = $this->cercle();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/trajets', ['duree_minutes' => 15, 'complice_ids' => [$c1->id], 'lat' => 5.30, 'lng' => -4.01, 'partage_position' => true])
            ->assertCreated()->json('data.id');

        // Dernière position connue (envoyée pendant le trajet)
        Carbon::setTestNow('2026-11-12 20:10:00');
        $this->postJson("/api/v1/trajets/{$id}/positions", ['lat' => 5.31, 'lng' => -4.02, 'precision_m' => 8])->assertCreated();

        // Avant l'échéance : rien
        Carbon::setTestNow('2026-11-12 20:14:00');
        $this->artisan('bravido:verifier-trajets')->assertSuccessful();
        $this->assertNull(Trajet::find($id)->rappel_envoye_at);

        // À l'échéance : rappel, pas encore d'alerte
        Carbon::setTestNow('2026-11-12 20:15:00');
        $this->artisan('bravido:verifier-trajets')->assertSuccessful();
        $this->assertNotNull(Trajet::find($id)->rappel_envoye_at);
        $this->assertSame('en_cours', Trajet::find($id)->statut);
        $this->assertSame(0, Alerte::count());

        // 1 min 59 après l'échéance : toujours rien
        Carbon::setTestNow('2026-11-12 20:16:59');
        $this->artisan('bravido:verifier-trajets')->assertSuccessful();
        $this->assertSame(0, Alerte::count());

        // Délai de grâce écoulé : alerte automatique
        Carbon::setTestNow('2026-11-12 20:17:00');
        $this->artisan('bravido:verifier-trajets')->assertSuccessful();
        $this->artisan('bravido:verifier-trajets')->assertSuccessful(); // idempotent

        $this->assertSame(1, Alerte::count());
        $alerte = Alerte::first();
        $trajet = Trajet::find($id);
        $this->assertSame('alerte', $trajet->statut);
        $this->assertSame($alerte->id, $trajet->alerte_id);
        $this->assertSame('autre', $alerte->motif);
        $this->assertSame('Trajet non confirmé', $alerte->libelleMotif());
        $this->assertEqualsWithDelta(5.31, $alerte->lat, 0.0001);
        $this->assertEqualsWithDelta(-4.02, $alerte->lng, 0.0001);
        // Seul le complice choisi est destinataire
        $this->assertSame([$p1->id], AlerteDestinataire::where('alerte_id', $alerte->id)->pluck('destinataire_user_id')->all());

        // Le complice voit l'alerte « Trajet non confirmé »
        Sanctum::actingAs($p1);
        $this->getJson('/api/v1/alertes-recues')->assertJsonPath('data.0.motif_libelle', 'Trajet non confirmé');
        Sanctum::actingAs($p2);
        $this->getJson('/api/v1/alertes-recues')->assertJsonCount(0, 'data');

        // L'utilisateur confirme enfin : l'alerte automatique est annulée
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/trajets/en-cours')->assertJsonPath('data.statut', 'alerte');
        $this->postJson("/api/v1/trajets/{$id}/arrive")->assertOk()->assertJsonPath('data.statut', 'arrive');
        $this->assertSame('annulee', $alerte->fresh()->statut);
    }

    public function test_prolongation_deux_fois_maximum(): void
    {
        Carbon::setTestNow('2026-11-12 20:00:00');
        [$user] = $this->cercle();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/trajets', ['duree_minutes' => 15])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/trajets/{$id}/prolonger")->assertOk()->assertJsonPath('data.extensions_restantes', 1);
        $this->postJson("/api/v1/trajets/{$id}/prolonger")->assertOk()->assertJsonPath('data.extensions_restantes', 0);
        $this->postJson("/api/v1/trajets/{$id}/prolonger")->assertStatus(409);
        $this->assertSame('20:45', Trajet::find($id)->echeance_at->format('H:i'));

        // L'ancienne échéance (20:15) ne déclenche plus rien
        Carbon::setTestNow('2026-11-12 20:20:00');
        $this->artisan('bravido:verifier-trajets')->assertSuccessful();
        $this->assertSame(0, Alerte::count());
        $this->assertNull(Trajet::find($id)->rappel_envoye_at);
    }

    public function test_acces_reserve_au_proprietaire_et_suivi_par_les_complices(): void
    {
        [$user, $p1, $p2, $c1] = $this->cercle();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/trajets', ['duree_minutes' => 30, 'complice_ids' => [$c1->id], 'partage_position' => true, 'lat' => 5.3, 'lng' => -4.0])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($p1);
        $this->postJson("/api/v1/trajets/{$id}/arrive")->assertNotFound();
        $this->postJson("/api/v1/trajets/{$id}/positions", ['lat' => 1, 'lng' => 1])->assertNotFound();
        $this->getJson('/api/v1/trajets-suivis')->assertJsonPath('data.0.personne', 'Awa')->assertJsonPath('data.0.position.lat', 5.3);

        // Complice non choisi : ne voit pas le trajet
        Sanctum::actingAs($p2);
        $this->getJson('/api/v1/trajets-suivis')->assertJsonCount(0, 'data');
    }

    public function test_purge_des_positions_de_trajet(): void
    {
        [$user] = $this->cercle();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/trajets', ['duree_minutes' => 15, 'lat' => 5.3, 'lng' => -4.0])->json('data.id');
        $this->postJson("/api/v1/trajets/{$id}/arrive")->assertOk();
        Trajet::whereKey($id)->update(['started_at' => now()->subDays(31)]);
        \App\Models\TrajetPosition::query()->update(['recorded_at' => now()->subDays(31)]);

        $this->artisan('bravido:purger')->assertSuccessful();
        $this->assertSame(0, \App\Models\TrajetPosition::count());
        $this->assertNull(Trajet::find($id));
    }
}
