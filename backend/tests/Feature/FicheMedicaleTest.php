<?php

namespace Tests\Feature;

use App\Models\Alerte;
use App\Models\Complice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FicheMedicaleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User} propriétaire et complice ayant accepté */
    private function cercle(): array
    {
        $user = User::factory()->create(['nom' => 'Awa']);
        $proche = User::factory()->create();
        $complice = new Complice(['nom' => 'Proche', 'telephone' => $proche->telephone, 'ordre' => 1]);
        $complice->user_id = $user->id;
        $complice->complice_user_id = $proche->id;
        $complice->statut = 'accepte';
        $complice->invitation_token = str_repeat('a', 48);
        $complice->save();

        return [$user, $proche];
    }

    private function fiche(): array
    {
        return [
            'groupe_sanguin' => 'O+',
            'allergies' => 'Pénicilline',
            'traitements' => 'Insuline',
            'pathologies' => 'Diabète',
            'personne_a_prevenir_nom' => 'Maman',
            'personne_a_prevenir_tel' => '0700000999',
            'notes' => 'Porte un bracelet',
        ];
    }

    public function test_la_fiche_est_privee_et_chiffree_au_repos(): void
    {
        [$user] = $this->cercle();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/fiche-medicale')->assertOk()->assertJsonPath('data.partage', false);
        $this->putJson('/api/v1/me/fiche-medicale', $this->fiche())->assertOk()->assertJsonPath('data.allergies', 'Pénicilline');
        $this->getJson('/api/v1/me/fiche-medicale')->assertJsonPath('data.groupe_sanguin', 'O+')->assertJsonPath('data.partage', false);

        $brut = DB::table('fiches_medicales')->where('user_id', $user->id)->first();
        $this->assertNotSame('Pénicilline', $brut->allergies);
        $this->assertStringNotContainsString('Insuline', $brut->traitements);
        $this->assertStringNotContainsString('Diabète', $brut->pathologies);
    }

    public function test_opt_in_et_opt_out_du_partage_pendant_une_alerte(): void
    {
        [$user, $proche] = $this->cercle();
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/me/fiche-medicale', $this->fiche())->assertOk();
        $id = $this->postJson('/api/v1/alertes', ['motif' => 'malaise'])->assertCreated()->json('data.id');

        // Par défaut : partage désactivé -> le complice ne voit rien
        Sanctum::actingAs($proche);
        $this->getJson("/api/v1/alertes/{$id}")->assertOk()->assertJsonPath('data.fiche_medicale', null);

        // Opt-in explicite
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/me/fiche-medicale', ['partage' => true])->assertOk()->assertJsonPath('data.partage', true);
        Sanctum::actingAs($proche);
        $this->getJson("/api/v1/alertes/{$id}")
            ->assertJsonPath('data.fiche_medicale.allergies', 'Pénicilline')
            ->assertJsonPath('data.fiche_medicale.groupe_sanguin', 'O+');
        $this->getJson('/api/v1/alertes-recues')->assertJsonPath('data.0.fiche_medicale.traitements', 'Insuline');

        // Opt-out : disparaît immédiatement
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/me/fiche-medicale', ['partage' => false])->assertOk();
        Sanctum::actingAs($proche);
        $this->getJson("/api/v1/alertes/{$id}")->assertJsonPath('data.fiche_medicale', null);
    }

    public function test_un_non_complice_ne_peut_rien_lire(): void
    {
        [$user] = $this->cercle();
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/me/fiche-medicale', $this->fiche() + ['partage' => true])->assertOk();
        $id = $this->postJson('/api/v1/alertes', ['motif' => 'malaise'])->json('data.id');

        $intrus = User::factory()->create();
        Sanctum::actingAs($intrus);
        $this->getJson("/api/v1/alertes/{$id}")->assertNotFound();
        $this->getJson('/api/v1/alertes-recues')->assertJsonCount(0, 'data');
        // /me/fiche-medicale ne renvoie que SA propre fiche (vide)
        $this->getJson('/api/v1/me/fiche-medicale')->assertJsonPath('data.allergies', null);
    }

    public function test_une_alerte_expiree_masque_la_fiche_et_un_complice_retire_aussi(): void
    {
        [$user, $proche] = $this->cercle();
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/me/fiche-medicale', $this->fiche() + ['partage' => true])->assertOk();
        $id = $this->postJson('/api/v1/alertes', ['motif' => 'malaise'])->json('data.id');

        // Complice ayant quitté le cercle (statut refusé) : plus d'accès
        Complice::where('user_id', $user->id)->update(['statut' => 'refuse']);
        Sanctum::actingAs($proche);
        $this->getJson("/api/v1/alertes/{$id}")->assertJsonPath('data.fiche_medicale', null);
        Complice::where('user_id', $user->id)->update(['statut' => 'accepte']);
        $this->getJson("/api/v1/alertes/{$id}")->assertJsonPath('data.fiche_medicale.allergies', 'Pénicilline');

        // Alerte arrivée à échéance
        Alerte::whereKey($id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/alertes/{$id}")->assertOk()->assertJsonPath('data.fiche_medicale', null);

        // Alerte clôturée
        Alerte::whereKey($id)->update(['expires_at' => now()->addHour(), 'statut' => 'close']);
        $this->getJson("/api/v1/alertes/{$id}")->assertJsonPath('data.fiche_medicale', null);
    }
}
