<?php

namespace Tests\Feature;

use App\Models\Etablissement;
use App\Models\EtablissementService;
use App\Models\Ville;
use App\Services\Recherche;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F10 : recherche globale. */
class RechercheTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);
    }

    private function noms(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('nom')->all();
    }

    private function service(Etablissement $e, string $service): void
    {
        EtablissementService::create(['etablissement_id' => $e->id, 'service' => $service]);
    }

    public function test_synonymes_francais_vers_categories(): void
    {
        $this->etab('Cabinet Sourire', [], 'dentaire');
        $this->etab('Pharmacie du Lac', [], 'pharmacie');
        $this->etab('Clinique Sainte Marie', [], 'hopital_prive');
        $this->etab('Vision Plus', [], 'optique');
        $this->etab('Veto Services', [], 'veterinaire');

        $this->assertSame(['Cabinet Sourire'], $this->noms('/api/v1/recherche?q=dentiste'));
        $this->assertSame(['Pharmacie du Lac'], $this->noms('/api/v1/recherche?q=phcie'));
        $this->assertSame(['Clinique Sainte Marie'], $this->noms('/api/v1/recherche?q=clinique'));
        $this->assertSame(['Vision Plus'], $this->noms('/api/v1/recherche?q=lunettes'));
        $this->assertSame(['Veto Services'], $this->noms('/api/v1/recherche?q=veto'));
    }

    public function test_synonyme_de_service_trouve_les_etablissements_qui_l_offrent(): void
    {
        $a = $this->etab('Hôpital Général', [], 'hopital_public');
        $this->service($a, 'Maternité');
        $b = $this->etab('Centre Sans Service', [], 'hopital_public');

        $r = $this->noms('/api/v1/recherche?q=maternite');
        $this->assertSame(['Hôpital Général'], $r);
        $this->assertNotContains($b->nom, $r);
    }

    public function test_insensible_aux_accents_et_a_la_casse(): void
    {
        $this->etab('Pharmacie Étoile', [], 'pharmacie');
        $this->etab('Hôpital Général Béni', [], 'hopital_public');

        $this->assertContains('Pharmacie Étoile', $this->noms('/api/v1/recherche?q=ETOILE'));
        $this->assertContains('Hôpital Général Béni', $this->noms('/api/v1/recherche?q=beni'));
        $this->assertContains('Hôpital Général Béni', $this->noms('/api/v1/recherche?q=hopital+general'));
        $this->assertSame('hopital general beni', Recherche::normaliser('  HÔPITAL  Général-Béni '));
    }

    public function test_tolerance_aux_fautes_de_frappe(): void
    {
        $this->etab('Pharmacie Bereka', [], 'pharmacie');
        $this->etab('Optique Soleil', [], 'optique');

        $this->assertContains('Optique Soleil', $this->noms('/api/v1/recherche?q=lunetes')); // synonyme mal écrit
        $this->assertContains('Pharmacie Bereka', $this->noms('/api/v1/recherche?q=pharmcie')); // catégorie mal écrite
        $this->assertContains('Pharmacie Bereka', $this->noms('/api/v1/recherche?q=berekka')); // nom mal écrit (vocabulaire)
    }

    public function test_classement_exact_puis_debut_puis_contient_puis_service(): void
    {
        $this->etab('Centre Delta', [], 'hopital_public');
        $this->etab('Pharmacie Delta', [], 'pharmacie');
        $this->etab('Delta', [], 'hopital_public');
        $this->etab('Grand Delta Nord', [], 'hopital_public');
        $s = $this->etab('Cabinet Zeta', [], 'hopital_prive');
        $this->service($s, 'Delta scan');

        $noms = $this->noms('/api/v1/recherche?q=delta');
        $this->assertSame('Delta', $noms[0]);                       // nom exact
        $this->assertSame('Cabinet Zeta', end($noms));              // service en dernier
        $this->assertLessThan(array_search('Cabinet Zeta', $noms), array_search('Grand Delta Nord', $noms));
    }

    public function test_nom_commencant_par_avant_nom_contenant(): void
    {
        $this->etab('Clinique du Soleil', [], 'hopital_prive');
        $this->etab('Soleil Levant', [], 'hopital_prive');
        $noms = $this->noms('/api/v1/recherche?q=soleil');
        $this->assertSame(['Soleil Levant', 'Clinique du Soleil'], $noms);
    }

    public function test_position_precise_et_plus_proche_en_premier_a_nom_egal(): void
    {
        $this->etab('Pharmacie Alpha Loin', ['position_precise' => true, 'lat' => 5.60, 'lng' => -4.0]);
        $this->etab('Pharmacie Alpha Pres', ['position_precise' => true, 'lat' => 5.361, 'lng' => -4.0083]);
        $this->etab('Pharmacie Alpha Approx');

        $r = $this->getJson('/api/v1/recherche?q=alpha&lat=5.36&lng=-4.0083')->assertOk();
        $this->assertSame(['Pharmacie Alpha Pres', 'Pharmacie Alpha Loin', 'Pharmacie Alpha Approx'], collect($r->json('data'))->pluck('nom')->all());
        $this->assertArrayNotHasKey('distance_m', $r->json('data.2')); // position approximative : jamais de distance
        $this->assertArrayHasKey('distance_m', $r->json('data.0'));
    }

    public function test_la_mise_a_la_une_premium_n_influence_pas_le_classement(): void
    {
        $this->etab('Pharmacie Beta Premium', ['premium' => true, 'premium_jusqu_au' => now()->addYear()->toDateString()]);
        $this->etab('Beta');

        $r = $this->getJson('/api/v1/recherche?q=beta')->assertOk();
        $this->assertSame('Beta', $r->json('data.0.nom'));
        $this->assertArrayNotHasKey('a_la_une', $r->json('data.0'));
        $this->assertArrayNotHasKey('a_la_une', $r->json('data.1'));
    }

    public function test_filtre_ville_et_categorie(): void
    {
        $this->etab('Pharmacie Gamma Cocody', [], 'pharmacie', 'Cocody');
        $this->etab('Pharmacie Gamma Bouaké', [], 'pharmacie', 'Bouaké');
        $this->etab('Optique Gamma', [], 'optique', 'Cocody');
        $abidjan = Ville::where('nom', 'Abidjan')->value('id');

        $this->assertSame(['Optique Gamma', 'Pharmacie Gamma Cocody'], collect($this->noms("/api/v1/recherche?q=gamma&ville_id=$abidjan"))->sort()->values()->all());
        $r = $this->getJson('/api/v1/recherche?q=gamma&categorie=optique')->assertOk();
        $this->assertSame(['Optique Gamma'], $r->json('data.*.nom'));
        // les comptes par catégorie restent ceux de toute la recherche (pour les puces de filtre)
        $this->assertSame(3, $r->json('meta.total') + 2);
        $this->assertEqualsCanonicalizing(['pharmacie' => 2, 'optique' => 1], collect($r->json('meta.groupes'))->pluck('total', 'code')->all());
    }

    public function test_maximum_40_resultats_et_total(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->etab("Pharmacie Omega $i");
        }
        $r = $this->getJson('/api/v1/recherche?q=omega')->assertOk();
        $this->assertCount(40, $r->json('data'));
        $this->assertSame(45, $r->json('meta.total'));
        $this->assertSame(45, $r->json('meta.groupes.0.total'));
    }

    public function test_brouillons_exclus_et_requete_trop_courte_refusee(): void
    {
        $this->etab('Pharmacie Sigma', ['statut' => 'brouillon']);
        $this->assertSame([], $this->noms('/api/v1/recherche?q=sigma'));
        $this->getJson('/api/v1/recherche?q=a')->assertStatus(422);
        $this->getJson('/api/v1/recherche')->assertStatus(422);
    }

    public function test_suggestions_au_plus_8_avec_types(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $e = $this->etab("Maternité Test $i", [], 'hopital_public');
            $this->service($e, 'Maternité');
        }
        $this->etab('Cabinet Dentaire X', [], 'dentaire');

        $r = $this->getJson('/api/v1/recherche/suggestions?q=mater')->assertOk()->json('data');
        $this->assertLessThanOrEqual(8, count($r));
        $this->assertContains('service', array_column($r, 'type'));
        $this->assertContains('etablissement', array_column($r, 'type'));

        $d = $this->getJson('/api/v1/recherche/suggestions?q=dentiste')->assertOk()->json('data');
        $this->assertSame('categorie', $d[0]['type']);
        $this->assertSame('dentaire', $d[0]['valeur']);
    }

    public function test_limitation_de_debit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/recherche?q=test')->assertOk();
        }
        $this->getJson('/api/v1/recherche?q=test')->assertStatus(429);
    }
}
