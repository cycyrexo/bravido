<?php

namespace Tests\Feature;

use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F11 : GET /etablissements/carte (bbox, positions précises uniquement, limite). */
class CarteTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private const BBOX = '5.30,-4.10,5.45,-3.90';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);
    }

    private function carte(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/etablissements/carte?bbox='.self::BBOX.$query);
    }

    public function test_seules_les_positions_precises_dans_la_zone(): void
    {
        $this->etab('Dans zone precise', ['position_precise' => true, 'lat' => 5.36, 'lng' => -4.00]);
        $this->etab('Dans zone approx', ['position_precise' => false, 'lat' => 5.36, 'lng' => -4.00]);
        $this->etab('Hors zone precise', ['position_precise' => true, 'lat' => 6.80, 'lng' => -5.28]);
        $this->etab('Brouillon precis', ['position_precise' => true, 'lat' => 5.36, 'lng' => -4.00, 'statut' => 'brouillon']);

        $r = $this->carte()->assertOk();
        $this->assertSame(['Dans zone precise'], $r->json('data.*.nom'));
        $this->assertSame(1, $r->json('meta.total'));
        $this->assertSame(1, $r->json('meta.sans_position')); // « N établissements sans position exacte »
        $this->assertFalse($r->json('meta.tronque'));
        // JSON allégé : pas d'adresse, téléphones, services...
        $this->assertEqualsCanonicalizing(['id', 'nom', 'categorie', 'lat', 'lng', 'h24'], array_keys($r->json('data.0')));
        $this->assertSame('pharmacie', $r->json('data.0.categorie'));
    }

    public function test_filtre_categorie(): void
    {
        $this->etab('Pharma', ['position_precise' => true, 'lat' => 5.36, 'lng' => -4.0], 'pharmacie');
        $this->etab('Optique', ['position_precise' => true, 'lat' => 5.37, 'lng' => -4.0], 'optique');

        $this->assertSame(['Optique'], $this->carte('&categorie=optique')->json('data.*.nom'));
        $this->assertCount(2, $this->carte('&categorie=optique,pharmacie')->json('data'));
        $this->assertCount(2, $this->carte()->json('data'));
    }

    public function test_limite_et_troncature(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->etab("P$i", ['position_precise' => true, 'lat' => 5.36 + $i / 1000, 'lng' => -4.0]);
        }
        $r = $this->carte('&limit=3')->assertOk();
        $this->assertCount(3, $r->json('data'));
        $this->assertSame(5, $r->json('meta.total'));
        $this->assertTrue($r->json('meta.tronque'));
        $this->carte('&limit=301')->assertStatus(422);
    }

    public function test_bbox_invalide_et_limitation_de_debit(): void
    {
        $this->getJson('/api/v1/etablissements/carte')->assertStatus(422);
        $this->getJson('/api/v1/etablissements/carte?bbox=abc')->assertStatus(422);
        $this->getJson('/api/v1/etablissements/carte?bbox=5.5,-4,5.3,-3.9')->assertStatus(422); // sud > nord
        for ($i = 0; $i < 57; $i++) {
            $this->carte()->assertOk();
        }
        $this->carte()->assertStatus(429);
    }

    public function test_la_route_ne_masque_pas_la_fiche(): void
    {
        $e = $this->etab('Fiche', ['position_precise' => true]);
        $this->getJson("/api/v1/etablissements/{$e->id}")->assertOk()->assertJsonPath('data.nom', 'Fiche');
    }
}
