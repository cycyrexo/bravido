<?php

namespace Tests\Feature;

use App\Models\Etablissement;
use App\Models\EtabPhoto;
use App\Models\EtabStat;
use App\Models\User;
use App\Models\Ville;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F17 : fiche Premium (contenus, expiration, mise « À la une », statistiques). */
class FichePremiumTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = $this->preparer();
        $this->app['auth']->forgetGuards();
    }

    private function premium(string $nom, array $attrs = [], string $cat = 'pharmacie'): Etablissement
    {
        return $this->etab($nom, $attrs + ['premium' => true, 'premium_jusqu_au' => today()->addMonth()], $cat);
    }

    private function responsable(Etablissement $e): User
    {
        $u = User::factory()->create();
        $e->forceFill(['responsable_id' => $u->id])->save();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $u;
    }

    private function villeId(): int
    {
        return Ville::where('nom', 'Abidjan')->value('id');
    }

    // ---- Validité ----

    public function test_validite_premium_flag_et_date_d_expiration(): void
    {
        $this->assertFalse($this->etab('A')->estPremium());
        $this->assertTrue($this->etab('B', ['premium' => true])->estPremium(), 'sans date de fin : actif');
        $this->assertTrue($this->etab('C', ['premium' => true, 'premium_jusqu_au' => today()])->estPremium(), 'le dernier jour inclus');
        $this->assertFalse($this->etab('D', ['premium' => true, 'premium_jusqu_au' => today()->subDay()])->estPremium());
        $this->assertFalse($this->etab('E', ['premium' => false, 'premium_jusqu_au' => today()->addYear()])->estPremium());

        $this->assertSame(['B', 'C'], Etablissement::premiumActif()->orderBy('nom')->pluck('nom')->all());
    }

    public function test_contenus_premium_masques_pour_une_fiche_non_premium_ou_expiree(): void
    {
        $e = $this->premium('Pharmacie Vitrine', ['description_longue' => 'Texte long', 'horaires_detail' => ['lun' => '08:00-20:00']]);
        $e->photos()->create(['chemin' => 'etablissements/1/a.jpg', 'miniature' => 'etablissements/1/a_t.jpg', 'ordre' => 1]);

        $r = $this->getJson("/api/v1/etablissements/{$e->id}")->assertOk();
        $r->assertJsonPath('data.premium', true)->assertJsonPath('data.description_longue', 'Texte long')
            ->assertJsonPath('data.horaires_detail.lun', '08:00-20:00')->assertJsonCount(1, 'data.photos');
        $this->assertStringContainsString('/storage/etablissements/1/a_t.jpg', $r->json('data.photos.0.miniature'));

        $e->forceFill(['premium_jusqu_au' => today()->subDay()])->save(); // expirée
        $r = $this->getJson("/api/v1/etablissements/{$e->id}")->assertOk();
        $r->assertJsonPath('data.premium', false);
        foreach (['description_longue', 'horaires_detail', 'photos'] as $cle) {
            $this->assertArrayNotHasKey($cle, $r->json('data'), "{$cle} masqué après expiration");
        }
    }

    // ---- Photos et horaires (responsable) ----

    public function test_photos_et_horaires_reserves_au_premium(): void
    {
        $e = $this->etab('Pharmacie Standard');
        $this->responsable($e);

        $this->post("/api/v1/mon-etablissement/{$e->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->putJson("/api/v1/mon-etablissement/{$e->id}/horaires-detail", ['jours' => ['lun' => '8h-18h']])->assertForbidden();
        $this->assertSame(0, EtabPhoto::count());

        // Premium expiré : toujours refusé
        $e->forceFill(['premium' => true, 'premium_jusqu_au' => today()->subDay()])->save();
        $this->post("/api/v1/mon-etablissement/{$e->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertForbidden();

        $e->forceFill(['premium_jusqu_au' => today()->addDay()])->save();
        $this->putJson("/api/v1/mon-etablissement/{$e->id}/horaires-detail", ['jours' => ['lun' => '08:00-20:00', 'dim' => 'Fermé', 'xxx' => 'ignoré', 'mar' => '']])
            ->assertOk()->assertJsonPath('data.lun', '08:00-20:00')->assertJsonPath('data.dim', 'Fermé')->assertJsonMissingPath('data.xxx')->assertJsonMissingPath('data.mar');
        $this->assertEquals(['lun' => '08:00-20:00', 'dim' => 'Fermé'], $e->fresh()->horaires_detail);
    }

    public function test_upload_photo_redimensionne_miniature_limite_a_cinq_et_suppression(): void
    {
        $e = $this->premium('Pharmacie Vitrine');
        $this->responsable($e);

        $r = $this->post("/api/v1/mon-etablissement/{$e->id}/photos", ['photo' => UploadedFile::fake()->image('grande.jpg', 3000, 2000)], ['Accept' => 'application/json'])
            ->assertCreated();
        $photo = EtabPhoto::first();
        Storage::disk('public')->assertExists([$photo->chemin, $photo->miniature]);
        [$l, $h] = getimagesizefromstring(Storage::disk('public')->get($photo->chemin));
        $this->assertSame(1280, max($l, $h));
        [$lt, $ht] = getimagesizefromstring(Storage::disk('public')->get($photo->miniature));
        $this->assertSame(400, max($lt, $ht));
        $this->assertSame($photo->id, EtabPhoto::first()->id);

        // PNG et fichier non image
        $this->post("/api/v1/mon-etablissement/{$e->id}/photos", ['photo' => UploadedFile::fake()->image('p.png', 200, 200)], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/mon-etablissement/{$e->id}/photos", ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

        foreach (range(1, 3) as $i) {
            $this->post("/api/v1/mon-etablissement/{$e->id}/photos", ['photo' => UploadedFile::fake()->image("p{$i}.jpg", 300, 300)], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->assertSame(5, EtabPhoto::count());
        $this->post("/api/v1/mon-etablissement/{$e->id}/photos", ['photo' => UploadedFile::fake()->image('6.jpg')], ['Accept' => 'application/json'])->assertStatus(422);

        $this->getJson("/api/v1/etablissements/{$e->id}")->assertJsonCount(5, 'data.photos');

        $this->deleteJson("/api/v1/mon-etablissement/{$e->id}/photos/{$photo->id}")->assertOk();
        $this->assertSame(4, EtabPhoto::count());
        Storage::disk('public')->assertMissing([$photo->chemin, $photo->miniature]);

        // Une photo d'un autre établissement n'est pas supprimable
        $autre = $this->premium('Autre');
        $p2 = $autre->photos()->create(['chemin' => 'etablissements/x/a.jpg', 'miniature' => 'etablissements/x/a_t.jpg', 'ordre' => 1]);
        $this->deleteJson("/api/v1/mon-etablissement/{$e->id}/photos/{$p2->id}")->assertNotFound();
    }

    // ---- « À la une » ----

    public function test_a_la_une_en_tete_de_la_categorie_sans_toucher_l_ordre_des_autres(): void
    {
        $this->etab('Alpha');
        $this->etab('Bravo');
        $this->etab('Charlie');
        $vedette = $this->premium('Zulu Premium');
        $ville = $this->villeId();

        $r = $this->getJson("/api/v1/pharmacies?ville_id={$ville}")->assertOk();
        $noms = collect($r->json('data'))->pluck('nom')->all();
        $this->assertSame(['Zulu Premium', 'Alpha', 'Bravo', 'Charlie'], $noms, 'premium en tête, reste inchangé, sans doublon');
        $this->assertTrue($r->json('data.0.a_la_une'));
        $this->assertArrayNotHasKey('a_la_une', $r->json('data.1'));
        $this->assertSame($vedette->id, $r->json('data.0.id'));

        // Sans filtre de ville : aucune mise en avant
        $noms = collect($this->getJson('/api/v1/pharmacies')->json('data'))->pluck('nom')->all();
        $this->assertSame(['Alpha', 'Bravo', 'Charlie', 'Zulu Premium'], $noms);
        // Recherche par nom : aucune mise en avant
        $data = $this->getJson("/api/v1/pharmacies?ville_id={$ville}&q=Zulu")->json('data');
        $this->assertSame(['Zulu Premium'], collect($data)->pluck('nom')->all());
        $this->assertArrayNotHasKey('a_la_une', $data[0]);
    }

    public function test_a_la_une_limite_a_deux_et_jamais_pour_une_fiche_expiree(): void
    {
        $this->etab('Alpha');
        $this->premium('Premium 1');
        $this->premium('Premium 2');
        $this->premium('Premium 3');
        $this->premium('Expiree', ['premium_jusqu_au' => today()->subDay()]);
        $ville = $this->villeId();

        $data = $this->getJson("/api/v1/pharmacies?ville_id={$ville}")->json('data');
        $flag = collect($data)->where('a_la_une', true)->pluck('nom')->all();
        $this->assertSame(['Premium 1', 'Premium 2'], $flag);
        $this->assertSame(['Premium 1', 'Premium 2', 'Alpha', 'Expiree', 'Premium 3'], collect($data)->pluck('nom')->all());
        $this->assertCount(5, $data);
    }

    public function test_a_la_une_garde_l_ordre_par_distance_quand_la_position_est_precise(): void
    {
        $proche = $this->etab('Proche', ['lat' => 5.3600, 'lng' => -4.0083, 'position_precise' => true]);
        $this->etab('Moyen', ['lat' => 5.3700, 'lng' => -4.0083, 'position_precise' => true]);
        $this->etab('Loin', ['lat' => 5.3900, 'lng' => -4.0083, 'position_precise' => true]);
        $this->premium('Premium Lointain', ['lat' => 5.4000, 'lng' => -4.0083, 'position_precise' => true]);
        $ville = $this->villeId();

        $data = $this->getJson("/api/v1/pharmacies?ville_id={$ville}&lat=5.3600&lng=-4.0083")->assertOk()->json('data');
        $this->assertSame(['Premium Lointain', 'Proche', 'Moyen', 'Loin'], collect($data)->pluck('nom')->all());
        $this->assertTrue($data[0]['a_la_une']);
        $this->assertGreaterThan(0, $data[0]['distance_m']);
        $this->assertSame($proche->id, $data[1]['id']);
    }

    public function test_jamais_a_la_une_dans_les_categories_d_urgence_ni_multi_categories(): void
    {
        $this->premium('Caserne Premium', [], 'centre_secours');
        $this->etab('Caserne B', [], 'centre_secours');
        $this->premium('FGA Premium', [], 'bureau_fga');
        $this->premium('Hopital Premium', [], 'hopital_public');
        $this->etab('Hopital A', [], 'hopital_public');
        $ville = $this->villeId();

        foreach (['centre_secours', 'bureau_fga'] as $code) {
            $data = $this->getJson("/api/v1/etablissements?categorie={$code}&ville_id={$ville}")->json('data');
            $this->assertNotEmpty($data);
            $this->assertEmpty(collect($data)->where('a_la_une', true), "{$code} : aucune mise en avant");
        }
        // Liste multi-catégories : pas de mise en avant
        $data = $this->getJson("/api/v1/etablissements?categorie=hopital_public,hopital_prive&ville_id={$ville}")->json('data');
        $this->assertEmpty(collect($data)->where('a_la_une', true));
        // Catégorie simple et non urgente : mise en avant
        $data = $this->getJson("/api/v1/etablissements?categorie=hopital_public&ville_id={$ville}")->json('data');
        $this->assertSame('Hopital Premium', $data[0]['nom']);
        $this->assertTrue($data[0]['a_la_une']);

        // Même une catégorie d'urgence marquée Premium ne sort pas en tête
        $noms = collect($this->getJson("/api/v1/etablissements?categorie=centre_secours&ville_id={$ville}")->json('data'))->pluck('nom')->all();
        $this->assertSame(['Caserne B', 'Caserne Premium'], $noms);
    }

    public function test_pharmacies_de_garde_sans_mise_en_avant(): void
    {
        $a = $this->etab('Alpha');
        $p = $this->premium('Premium Garde');
        foreach ([$a, $p] as $e) {
            \App\Models\Garde::create(['etablissement_id' => $e->id, 'date_debut' => today(), 'date_fin' => today()->addDays(3)]);
        }
        $data = $this->getJson('/api/v1/pharmacies/garde?ville_id='.$this->villeId())->assertOk()->json('data');
        $this->assertSame(['Alpha', 'Premium Garde'], collect($data)->pluck('nom')->all());
        $this->assertEmpty(collect($data)->where('a_la_une', true));
    }

    // ---- Statistiques ----

    public function test_evenements_anonymes_agreges_par_jour(): void
    {
        $e = $this->etab('Pharmacie Stats');
        foreach (['vue', 'vue', 'vue', 'appel', 'itineraire', 'itineraire'] as $type) {
            $this->postJson("/api/v1/etablissements/{$e->id}/evenements", ['type' => $type])->assertNoContent();
        }
        $ligne = EtabStat::where('etablissement_id', $e->id)->get();
        $this->assertCount(1, $ligne, 'une ligne par jour');
        $this->assertSame([3, 1, 2], [$ligne[0]->vues, $ligne[0]->appels, $ligne[0]->itineraires]);
        $this->assertFalse(Schema::hasColumn('etab_stats', 'user_id'), 'aucun identifiant d\'utilisateur stocké');
        $this->assertEqualsCanonicalizing(['id', 'etablissement_id', 'jour', 'vues', 'appels', 'itineraires'], Schema::getColumnListing('etab_stats'));

        $this->postJson("/api/v1/etablissements/{$e->id}/evenements", ['type' => 'clic'])->assertStatus(422);
        $this->postJson("/api/v1/etablissements/{$e->id}/evenements", [])->assertStatus(422);
        $brouillon = $this->etab('Brouillon', ['statut' => 'brouillon']);
        $this->postJson("/api/v1/etablissements/{$brouillon->id}/evenements", ['type' => 'vue'])->assertNotFound();
    }

    public function test_statistiques_sur_30_jours_visibles_du_responsable(): void
    {
        $e = $this->etab('Pharmacie Stats');
        $ancien = EtabStat::create(['etablissement_id' => $e->id, 'jour' => today()->subDays(40), 'vues' => 100, 'appels' => 100, 'itineraires' => 100]);
        EtabStat::create(['etablissement_id' => $e->id, 'jour' => today()->subDays(29), 'vues' => 5, 'appels' => 2, 'itineraires' => 1]);
        EtabStat::create(['etablissement_id' => $e->id, 'jour' => today()->subDays(3), 'vues' => 7, 'appels' => 1, 'itineraires' => 0]);
        $this->postJson("/api/v1/etablissements/{$e->id}/evenements", ['type' => 'vue'])->assertNoContent();

        $this->responsable($e);
        $this->getJson("/api/v1/mon-etablissement/{$e->id}")->assertOk()
            ->assertJsonPath('data.stats', ['vues' => 13, 'appels' => 3, 'itineraires' => 1, 'jours' => 30]);
        $this->getJson('/api/v1/mon-etablissement')->assertJsonPath('data.0.stats.vues', 13);
        $this->assertNotNull($ancien);
    }

    // ---- Back-office ----

    public function test_page_admin_premium_activation_edition_et_photos(): void
    {
        $e = $this->etab('Pharmacie Cible');
        EtabStat::create(['etablissement_id' => $e->id, 'jour' => today(), 'vues' => 12, 'appels' => 3, 'itineraires' => 2]);
        $this->actingAs($this->admin, 'web');

        $this->get('/admin/premium')->assertOk()->assertSee('Aucune fiche Premium');
        $this->get('/admin/premium?q=Cible')->assertOk()->assertSee('Pharmacie Cible')->assertSee('Activer Premium');

        $date = today()->addYear()->toDateString();
        $this->post("/admin/premium/{$e->id}/activer", ['premium_jusqu_au' => $date])->assertRedirect(route('admin.premium.edit', $e));
        $this->assertTrue($e->fresh()->estPremium());
        $this->assertSame($date, $e->fresh()->premium_jusqu_au->toDateString());

        $this->get("/admin/premium/{$e->id}")->assertOk()->assertSee('Vues de la fiche')->assertSee('12');
        $this->put("/admin/premium/{$e->id}", [
            'premium' => 1, 'premium_jusqu_au' => $date, 'description_longue' => 'Une description complète.',
            'jours' => ['lun' => '08:00-20:00', 'sam' => '09:00-13:00'],
        ])->assertRedirect();
        $this->assertEquals(['lun' => '08:00-20:00', 'sam' => '09:00-13:00'], $e->fresh()->horaires_detail);
        $this->assertSame('Une description complète.', $e->fresh()->description_longue);

        $this->post("/admin/premium/{$e->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 1600, 900)])->assertRedirect();
        $this->assertSame(1, $e->photos()->count());
        $this->get('/admin/premium')->assertOk()->assertSee('Pharmacie Cible')->assertSee('Actif')->assertSee('1/5');
        $photo = $e->photos()->first();
        $this->delete("/admin/premium/{$e->id}/photos/{$photo->id}")->assertRedirect();
        $this->assertSame(0, $e->photos()->count());

        // Décocher Premium
        $this->put("/admin/premium/{$e->id}", ['jours' => []])->assertRedirect();
        $this->assertFalse($e->fresh()->estPremium());
        $this->assertTrue(\App\Models\JournalAdmin::where('action', 'etablissement.premium')->exists());
    }

    public function test_page_premium_reservee_aux_administrateurs(): void
    {
        $mod = User::factory()->create();
        $mod->forceFill(['role' => 'moderateur'])->save();
        $this->actingAs($mod, 'web');
        $this->get('/admin/premium')->assertForbidden();
    }
}
