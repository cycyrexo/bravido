<?php

namespace Tests\Feature;

use App\Models\Etablissement;
use App\Models\JournalAdmin;
use App\Models\PropositionPosition;
use App\Models\User;
use App\Services\PositionsUtilisateurs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F2 : positions confirmées par les utilisateurs sur place. */
class PositionsUtilisateursTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->preparer();
    }

    private function proposer(Etablissement $e, float $lat, float $lng, float $precision = 10, ?User $user = null)
    {
        Sanctum::actingAs($user ?? User::factory()->create());

        return $this->postJson("/api/v1/etablissements/{$e->id}/positions-proposees", [
            'lat' => $lat, 'lng' => $lng, 'precision_m' => $precision,
        ]);
    }

    public function test_proposition_acceptee_puis_une_seule_par_utilisateur(): void
    {
        $e = $this->etab('Pharmacie Test');
        $u = User::factory()->create();

        $this->proposer($e, 5.3601, -4.0084, 12, $u)->assertCreated();
        $this->assertSame(1, PropositionPosition::count());

        $this->proposer($e, 5.3602, -4.0085, 12, $u)->assertStatus(409);
        $this->assertSame(1, PropositionPosition::count());
        $this->assertNull($e->fresh()->position_a_valider_at); // jamais publié ni signalé avec 1 seule proposition
        $this->assertSame(5.36, $e->fresh()->lat);
    }

    public function test_refus_precision_trop_faible_ville_trop_loin_et_position_deja_verifiee(): void
    {
        $e = $this->etab('Pharmacie Test');

        $this->proposer($e, 5.3601, -4.0084, 51)->assertStatus(422);
        $this->proposer($e, 5.3601, -4.0084, 50)->assertCreated();            // la limite est incluse
        $this->proposer($e, 6.8276, -5.2893, 10)->assertStatus(422);           // Yamoussoukro : > 25 km du centre d'Abidjan
        $this->proposer($e, 5.3601, -4.0084)->assertCreated();

        $precise = $this->etab('Deja verifiee', ['position_precise' => true]);
        $this->proposer($precise, 5.3601, -4.0084)->assertStatus(409);

        $brouillon = $this->etab('Brouillon', ['statut' => 'brouillon']);
        $this->proposer($brouillon, 5.3601, -4.0084)->assertNotFound();

        $this->postJson("/api/v1/etablissements/{$e->id}/positions-proposees", ['lat' => 5.36])->assertStatus(422);
    }

    public function test_authentification_obligatoire(): void
    {
        $e = $this->etab('Pharmacie Test');
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/etablissements/{$e->id}/positions-proposees", ['lat' => 5.36, 'lng' => -4.0, 'precision_m' => 5])
            ->assertUnauthorized();
    }

    public function test_seuil_de_trois_utilisateurs_distincts_dans_60_m(): void
    {
        $e = $this->etab('Pharmacie Test');

        $this->proposer($e, 5.3601, -4.0084)->assertCreated();
        $this->proposer($e, 5.3602, -4.0085)->assertCreated();
        $this->assertNull($e->fresh()->position_a_valider_at, '2 utilisateurs : pas assez');

        $this->proposer($e, 5.3601, -4.0083)->assertCreated();
        $this->assertNotNull($e->fresh()->position_a_valider_at, '3 utilisateurs concordants : signalé');
        $this->assertSame(5.36, $e->fresh()->lat, 'jamais publié automatiquement');
        $this->assertFalse($e->fresh()->position_precise);
    }

    public function test_trois_propositions_dispersees_ne_declenchent_rien(): void
    {
        $e = $this->etab('Pharmacie Test');
        // ~ 110 m d'écart entre chaque point : aucune médiane ne regroupe 3 utilisateurs
        $this->proposer($e, 5.3600, -4.0090)->assertCreated();
        $this->proposer($e, 5.3610, -4.0090)->assertCreated();
        $this->proposer($e, 5.3620, -4.0090)->assertCreated();

        $this->assertNull($e->fresh()->position_a_valider_at);
        $this->assertNull(app(PositionsUtilisateurs::class)->consensus($e));
    }

    public function test_valeurs_aberrantes_ecartees_du_consensus(): void
    {
        $e = $this->etab('Pharmacie Test');
        foreach ([[5.3601, -4.0084], [5.3602, -4.0085], [5.3601, -4.0083], [5.3700, -4.0200], [5.3500, -4.0000]] as [$lat, $lng]) {
            $this->proposer($e, $lat, $lng)->assertCreated();
        }

        $c = app(PositionsUtilisateurs::class)->consensus($e);
        $this->assertNotNull($c);
        $this->assertSame(3, $c['nb']);
        $this->assertSame(5, $c['total']);
        $this->assertEqualsWithDelta(5.3601, $c['lat'], 0.0002);
        $this->assertEqualsWithDelta(-4.0084, $c['lng'], 0.0002);
    }

    public function test_validation_au_back_office_met_la_position_et_supprime_les_propositions(): void
    {
        $e = $this->etab('Pharmacie Test');
        foreach ([[5.3601, -4.0084], [5.3602, -4.0086], [5.3603, -4.0085]] as [$lat, $lng]) {
            $this->proposer($e, $lat, $lng)->assertCreated();
        }
        $this->actingAs($this->admin, 'web');

        $html = $this->get('/admin/geolocalisation')->assertOk()->getContent();
        $this->assertStringContainsString('Propositions des utilisateurs', $html);
        $this->assertStringContainsString('Pharmacie Test', $html);
        $this->assertStringContainsString('3 utilisateur(s) concordant(s)', $html);
        // Vie privée : aucun identifiant ni téléphone d'utilisateur dans la page
        foreach (User::where('id', '!=', $this->admin->id)->get() as $u) {
            $this->assertStringNotContainsString((string) $u->telephone, $html);
        }

        $this->post("/admin/geolocalisation/propositions/{$e->id}/valider")->assertRedirect();

        $e->refresh();
        $this->assertTrue($e->position_precise);
        $this->assertEqualsWithDelta(5.3602, $e->lat, 0.0001);
        $this->assertEqualsWithDelta(-4.0085, $e->lng, 0.0001);
        $this->assertNotNull($e->verifie_at);
        $this->assertNull($e->position_a_valider_at);
        $this->assertSame(0, PropositionPosition::count());
        $this->assertTrue(JournalAdmin::where('action', 'etablissement.position_utilisateurs_validee')->exists());
    }

    public function test_rejet_supprime_les_propositions_sans_toucher_la_fiche(): void
    {
        $e = $this->etab('Pharmacie Test');
        foreach ([[5.3601, -4.0084], [5.3602, -4.0086], [5.3603, -4.0085]] as [$lat, $lng]) {
            $this->proposer($e, $lat, $lng)->assertCreated();
        }
        $this->actingAs($this->admin, 'web');

        $this->post("/admin/geolocalisation/propositions/{$e->id}/rejeter")->assertRedirect();

        $e->refresh();
        $this->assertFalse($e->position_precise);
        $this->assertSame(5.36, $e->lat);
        $this->assertNull($e->position_a_valider_at);
        $this->assertSame(0, PropositionPosition::count());
        $this->assertTrue(JournalAdmin::where('action', 'etablissement.position_utilisateurs_rejetee')->exists());

        // La validation d'une proposition qui n'est plus valable est refusée
        $this->post("/admin/geolocalisation/propositions/{$e->id}/valider")->assertRedirect();
        $this->assertFalse($e->fresh()->position_precise);
    }

    public function test_purge_apres_90_jours_et_reevaluation(): void
    {
        $e = $this->etab('Pharmacie Test');
        foreach ([[5.3601, -4.0084], [5.3602, -4.0086], [5.3603, -4.0085]] as [$lat, $lng]) {
            $this->proposer($e, $lat, $lng)->assertCreated();
        }
        $this->assertNotNull($e->fresh()->position_a_valider_at);

        PropositionPosition::query()->limit(2)->update(['created_at' => now()->subDays(91)]);
        $this->artisan('bravido:purger')->assertSuccessful();

        $this->assertSame(1, PropositionPosition::count());
        $this->assertNull($e->fresh()->position_a_valider_at, 'plus assez de propositions : le signalement disparaît');
    }
}
