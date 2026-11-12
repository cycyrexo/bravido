<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Commune;
use App\Models\Etablissement;
use App\Models\Garde;
use App\Models\Ville;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PharmaciesGardeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);
    }

    public function test_gardes_en_cours_triees_par_distance(): void
    {
        $proche = $this->pharmacie('Proche', 5.3600, -4.0000);
        $loin = $this->pharmacie('Loin', 5.4200, -4.0200);
        $brouillon = $this->pharmacie('Brouillon', 5.3601, -4.0001, 'brouillon');
        $pasDeGarde = $this->pharmacie('Pas de garde', 5.3602, -4.0002);
        $gardeFuture = $this->pharmacie('Garde future', 5.3603, -4.0003);

        foreach ([$proche, $loin, $brouillon] as $p) {
            Garde::create(['etablissement_id' => $p->id, 'date_debut' => today()->subDay(), 'date_fin' => today()->addDays(5)]);
        }
        Garde::create(['etablissement_id' => $gardeFuture->id, 'date_debut' => today()->addDays(7), 'date_fin' => today()->addDays(14)]);

        $villeId = Ville::where('nom', 'Abidjan')->value('id');

        $reponse = $this->getJson("/api/v1/pharmacies/garde?ville_id={$villeId}&lat=5.36&lng=-4.0")->assertOk();

        $this->assertSame(['Proche', 'Loin'], array_column($reponse->json('data'), 'nom'));
        $this->assertLessThan($reponse->json('data.1.distance_m'), $reponse->json('data.0.distance_m'));
        $this->assertSame(today()->subDay()->toDateString(), $reponse->json('periode.du'));
    }

    public function test_filtre_par_assurance(): void
    {
        $avec = $this->pharmacie('Avec MUGEFCI', 5.36, -4.0);
        $sans = $this->pharmacie('Sans MUGEFCI', 5.361, -4.0);
        $mugefci = \App\Models\Assurance::where('nom', 'MUGEFCI')->value('id');
        $avec->assurances()->attach($mugefci);

        $noms = array_column(
            $this->getJson("/api/v1/pharmacies?assurance_id={$mugefci}")->assertOk()->json('data'),
            'nom'
        );

        $this->assertSame(['Avec MUGEFCI'], $noms);
    }

    public function test_fiche_brouillon_introuvable(): void
    {
        $p = $this->pharmacie('Cachée', 5.36, -4.0, 'brouillon');
        $this->getJson("/api/v1/etablissements/{$p->id}")->assertNotFound();
    }

    public function test_position_approximative_sans_distance_et_triee_apres(): void
    {
        $approx = $this->pharmacie('AAA Approximative', 5.3600, -4.0000);
        $approx->position_precise = false;
        $approx->save();
        $this->pharmacie('Précise loin', 5.4200, -4.0200);
        $this->pharmacie('Précise proche', 5.3605, -4.0000);

        $data = $this->getJson('/api/v1/pharmacies?lat=5.36&lng=-4.0')->assertOk()->json('data');

        $this->assertSame(['Précise proche', 'Précise loin', 'AAA Approximative'], array_column($data, 'nom'));
        $this->assertFalse($data[0]['position_approx']);
        $this->assertNotNull($data[0]['distance_m']);
        $this->assertTrue($data[2]['position_approx']);
        $this->assertNull($data[2]['distance_m'] ?? null);
    }

    private function pharmacie(string $nom, float $lat, float $lng, string $statut = 'publie'): Etablissement
    {
        return Etablissement::create([
            'categorie_id' => Categorie::idPour('pharmacie'),
            'nom' => $nom,
            'commune_id' => Commune::where('nom', 'Cocody')->value('id'),
            'lat' => $lat,
            'lng' => $lng,
            'statut' => $statut,
        ]);
    }
}
