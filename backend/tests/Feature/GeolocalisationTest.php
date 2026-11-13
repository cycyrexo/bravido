<?php

namespace Tests\Feature;

use App\Models\GeocodeCache;
use App\Models\JournalAdmin;
use App\Services\Geolocalisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

class GeolocalisationTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparer();
        config(['services.nominatim.delai' => 0]);
    }

    public function test_page_liste_publies_d_abord_et_filtre(): void
    {
        $this->etab('Brouillon A', ['statut' => 'brouillon']);
        $this->etab('Publiee B');
        $this->etab('Deja precise', ['position_precise' => true]);

        $html = $this->get('/admin/geolocalisation')->assertOk()->getContent();

        $this->assertStringContainsString('2 restant(s)', $html);
        $this->assertStringNotContainsString('Deja precise', $html);
        $this->assertLessThan(strpos($html, 'Brouillon A'), strpos($html, 'Publiee B'));

        $this->get('/admin/geolocalisation?commune_id=999999')->assertOk()->assertSee('Rien à géolocaliser');
    }

    public function test_candidats_filtres_par_rayon_et_mis_en_cache(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '5.3610', 'lon' => '-4.0090', 'display_name' => 'Pharmacie Proche, Cocody'],
            ['lat' => '6.8276', 'lon' => '-5.2893', 'display_name' => 'Homonyme à Yamoussoukro'], // hors rayon : écarté
            ['lat' => '5.36101', 'lon' => '-4.00901', 'display_name' => 'Quasi-doublon'],          // à moins de 30 m : fusionné
        ])]);
        $e = $this->etab('Pharmacie Test');

        $r = $this->getJson("/admin/geolocalisation/{$e->id}/candidats")->assertOk();
        $this->assertCount(1, $r->json('candidats'));
        $this->assertEqualsWithDelta(5.361, $r->json('candidats.0.lat'), 0.0001);

        // Requête déjà faite : lue en cache, aucun nouvel appel réseau
        $this->getJson("/admin/geolocalisation/{$e->id}/candidats")->assertOk();
        Http::assertSentCount(1);
        $this->assertSame(1, GeocodeCache::count());
        $this->assertTrue(GeocodeCache::first()->expires_at->between(now()->addDays(89), now()->addDays(91)));

        Http::assertSent(fn ($req) => str_contains($req->header('User-Agent')[0], 'BRAVIDO-backoffice/1.0')
            && $req['countrycodes'] === 'ci');
    }

    public function test_cache_expire_est_redemande(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $e = $this->etab('Pharmacie Vieille');
        $this->getJson("/admin/geolocalisation/{$e->id}/candidats")->assertOk();
        GeocodeCache::query()->update(['expires_at' => now()->subDay()]);

        $this->getJson("/admin/geolocalisation/{$e->id}/candidats")->assertOk();
        Http::assertSentCount(2);
    }

    public function test_brouillon_sans_nom_utilise_le_repere(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $e = $this->etab('(nom à compléter — page 5)', ['repere' => 'Face au marché']);

        $this->getJson("/admin/geolocalisation/{$e->id}/candidats")->assertOk()->assertJsonCount(0, 'candidats');
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_starts_with($req['q'], 'Face au marché, Cocody'));
    }

    public function test_valider_met_a_jour_et_journalise(): void
    {
        $e = $this->etab('Pharmacie À Valider');
        $this->etab('Autre');

        $this->postJson("/admin/geolocalisation/{$e->id}/valider", ['lat' => 5.3555, 'lng' => -3.99])
            ->assertOk()->assertJson(['ok' => true, 'restants' => 1]);

        $e->refresh();
        $this->assertTrue($e->position_precise);
        $this->assertEqualsWithDelta(5.3555, $e->lat, 0.00001);
        $this->assertNotNull($e->verifie_at);
        $j = JournalAdmin::where('action', 'etablissement.geolocalisation')->firstOrFail();
        $this->assertFalse($j->avant['position_precise']);
        $this->assertTrue($j->apres['position_precise']);
    }

    public function test_valider_refuse_un_point_hors_de_la_ville(): void
    {
        $e = $this->etab('Pharmacie Loin');

        $this->postJson("/admin/geolocalisation/{$e->id}/valider", ['lat' => 6.8276, 'lng' => -5.2893])->assertStatus(422);
        $this->postJson("/admin/geolocalisation/{$e->id}/valider", ['lat' => 'x', 'lng' => 1])->assertStatus(422);
        $this->assertFalse($e->fresh()->position_precise);
    }

    public function test_ignorer_retire_de_la_file_pour_la_session(): void
    {
        $a = $this->etab('Pharmacie A');
        $this->etab('Pharmacie B');

        $this->postJson("/admin/geolocalisation/{$a->id}/ignorer")->assertOk()->assertJson(['restants' => 1]);
        $this->get('/admin/geolocalisation')->assertOk()->assertDontSee('Pharmacie A')->assertSee('Pharmacie B');
        $this->assertFalse($a->fresh()->position_precise); // rien n'est modifié en base

        $this->post('/admin/geolocalisation/reinitialiser')->assertRedirect();
        $this->get('/admin/geolocalisation')->assertSee('Pharmacie A');
    }

    public function test_distance_haversine(): void
    {
        $this->assertEqualsWithDelta(111_195, Geolocalisation::distanceM(0, 0, 1, 0), 300);
    }

    public function test_nom_simplifie_retire_prefixe_lieu_et_apostrophes_typographiques(): void
    {
        $this->assertSame("NDOTRE PHARMACIE N'DOTRE", Geolocalisation::nomSimplifie('ABOBO PK 18 - NDOTRE PHARMACIE N’DOTRE'));
        $this->assertSame('PHARMACIE OLYMPIQUE', Geolocalisation::nomSimplifie('PHARMACIE OLYMPIQUE'));
        $this->assertSame('PHARMACIE DU PORT', Geolocalisation::nomSimplifie('PHARMACIE DU PORT (ex-Centrale)'));
    }

    public function test_acces_interdit_aux_non_staff(): void
    {
        auth()->logout();
        $this->get('/admin/geolocalisation')->assertRedirect();
    }
}
