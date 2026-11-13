<?php

namespace Tests\Feature;

use App\Models\Etablissement;
use App\Models\Fiche;
use App\Models\Garde;
use App\Models\GardeDeclaration;
use App\Models\JournalAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F18 : revendication, espace du responsable, déclaration de garde modérée. */
class EspaceResponsableTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->preparer();
    }

    private function enTantQue(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    private function enAdmin(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web');
    }

    private function responsable(Etablissement $e): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'resp_etab'])->save();
        $e->forceFill(['responsable_id' => $u->id])->save();

        return $u;
    }

    public function test_revendication_puis_validation_donne_le_role_et_l_acces(): void
    {
        $e = $this->etab('Pharmacie du Plateau');
        $u = User::factory()->create();
        $this->enTantQue($u);

        $this->post('/api/v1/fiches', [
            'type' => 'revendication', 'etablissement_id' => $e->id,
            'donnees' => ['role' => 'Pharmacien titulaire', 'telephone' => '0700000777'],
            'pieces' => [UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.type', 'revendication');

        $fiche = Fiche::first();
        $this->assertSame('en_attente', $fiche->statut);
        $this->assertSame($e->id, $fiche->etablissement_id);
        $this->assertCount(1, $fiche->pieces);
        $this->assertNull($e->fresh()->responsable_id, 'rien n\'est accordé avant la modération');

        // Demande en double
        $this->post('/api/v1/fiches', ['type' => 'revendication', 'etablissement_id' => $e->id,
            'donnees' => ['role' => 'Gérant', 'telephone' => '0700000777']], ['Accept' => 'application/json'])->assertStatus(409);
        // Données obligatoires
        $this->postJson('/api/v1/fiches', ['type' => 'revendication', 'etablissement_id' => $e->id, 'donnees' => ['nom' => 'x']])->assertStatus(422);

        // Le back-office affiche la demande puis la valide
        $this->enAdmin();
        $this->get('/admin/fiches')->assertOk()->assertSee('revendication');
        $this->get("/admin/fiches/{$fiche->id}")->assertOk()->assertSee('Pharmacien titulaire')->assertSee('Demande de gestion');
        $this->post("/admin/fiches/{$fiche->id}/valider")->assertRedirect(route('admin.fiches.index'));

        $this->assertSame($u->id, $e->fresh()->responsable_id);
        $this->assertSame('resp_etab', $u->fresh()->role);
        $this->assertSame('validee', $fiche->fresh()->statut);
        $this->assertTrue(JournalAdmin::where('action', 'fiche.validation_revendication')->exists());

        // L'utilisateur voit maintenant son établissement et ne peut plus le revendiquer
        $this->enTantQue($u->fresh());
        $this->getJson('/api/v1/mon-etablissement')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $e->id);
        $this->getJson('/api/v1/me')->assertJsonPath('data.nb_etablissements', 1);
        $this->postJson('/api/v1/fiches', ['type' => 'revendication', 'etablissement_id' => $e->id,
            'donnees' => ['role' => 'Gérant', 'telephone' => '0700000777']])->assertStatus(422);
    }

    public function test_validation_conserve_le_role_d_un_moderateur_et_le_rejet_motive(): void
    {
        $e = $this->etab('Pharmacie A');
        $f = $this->etab('Pharmacie B');
        $mod = User::factory()->create();
        $mod->forceFill(['role' => 'moderateur'])->save();
        $u = User::factory()->create();

        foreach ([[$mod, $e], [$u, $f]] as [$qui, $etab]) {
            $this->enTantQue($qui);
            $this->postJson('/api/v1/fiches', ['type' => 'revendication', 'etablissement_id' => $etab->id,
                'donnees' => ['role' => 'Gérant', 'telephone' => '0700000777']])->assertCreated();
        }
        $this->enAdmin();
        $this->post('/admin/fiches/'.Fiche::where('soumis_par', $mod->id)->value('id').'/valider')->assertRedirect();
        $this->assertSame('moderateur', $mod->fresh()->role);
        $this->assertSame($mod->id, $e->fresh()->responsable_id);

        $this->post('/admin/fiches/'.Fiche::where('soumis_par', $u->id)->value('id').'/rejeter', ['motif_rejet' => 'Justificatif illisible'])->assertRedirect();
        $this->assertNull($f->fresh()->responsable_id);
        $this->assertSame('user', $u->fresh()->role);
    }

    public function test_un_non_responsable_est_refuse_partout(): void
    {
        $e = $this->etab('Pharmacie du Plateau');
        $this->responsable($e);
        $intrus = User::factory()->create();
        $this->enTantQue($intrus);

        $this->getJson('/api/v1/mon-etablissement')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/mon-etablissement/{$e->id}")->assertForbidden();
        $this->postJson("/api/v1/mon-etablissement/{$e->id}/corrections", ['tel1' => '0700000001'])->assertForbidden();
        $this->postJson("/api/v1/mon-etablissement/{$e->id}/garde", ['date_debut' => today()->toDateString(), 'date_fin' => today()->addDays(6)->toDateString()])->assertForbidden();
        $this->post("/api/v1/mon-etablissement/{$e->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->putJson("/api/v1/mon-etablissement/{$e->id}/horaires-detail", ['jours' => ['lun' => '8h-18h']])->assertForbidden();

        // Un établissement sans responsable n'est accessible à personne
        $libre = $this->etab('Sans responsable');
        $this->getJson("/api/v1/mon-etablissement/{$libre->id}")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mon-etablissement')->assertUnauthorized();
    }

    public function test_correction_passe_par_la_moderation_et_ignore_les_champs_sensibles(): void
    {
        $e = $this->etab('Pharmacie du Plateau', ['tel1' => '0700000000']);
        $u = $this->responsable($e);
        $this->enTantQue($u);

        $this->getJson("/api/v1/mon-etablissement/{$e->id}")->assertOk()
            ->assertJsonPath('data.id', $e->id)->assertJsonPath('data.peut_declarer_garde', true)
            ->assertJsonPath('data.stats.vues', 0);

        $this->postJson("/api/v1/mon-etablissement/{$e->id}/corrections", [
            'tel1' => '0701020304', 'horaires' => 'Lun-Sam 8h-20h', 'services' => ['Livraison'],
            'lat' => 1.0, 'lng' => 1.0, 'nom' => 'Hack', 'description_longue' => 'réservé premium',
        ])->assertCreated();

        $fiche = Fiche::first();
        $this->assertSame('correction', $fiche->type);
        $this->assertSame('en_attente', $fiche->statut);
        $this->assertSame(['tel1', 'horaires', 'services'], array_keys($fiche->donnees), 'ni GPS, ni nom, ni description Premium');
        $this->assertSame('0700000000', $e->fresh()->tel1, 'rien n\'est appliqué sans validation');

        $this->postJson("/api/v1/mon-etablissement/{$e->id}/corrections", ['tel1' => '0705050505'])->assertStatus(409);
        $this->postJson("/api/v1/mon-etablissement/{$e->id}/corrections", [])->assertStatus(422);

        $this->enAdmin();
        $this->post("/admin/fiches/{$fiche->id}/valider")->assertRedirect();
        $this->assertSame('0701020304', $e->fresh()->tel1);
        $this->assertSame('Lun-Sam 8h-20h', $e->fresh()->horaires);
    }

    public function test_declaration_de_garde_validations_et_chevauchements(): void
    {
        $e = $this->etab('Pharmacie du Plateau');
        $u = $this->responsable($e);
        $this->enTantQue($u);
        $url = "/api/v1/mon-etablissement/{$e->id}/garde";
        $debut = today()->addDays(2)->toDateString();
        $fin = today()->addDays(8)->toDateString();

        $this->postJson($url, ['date_debut' => today()->subDay()->toDateString(), 'date_fin' => $fin])->assertStatus(422);
        $this->postJson($url, ['date_debut' => $fin, 'date_fin' => $debut])->assertStatus(422);
        $this->postJson($url, ['date_debut' => $debut, 'date_fin' => today()->addDays(30)->toDateString()])->assertStatus(422); // > 14 jours
        $this->postJson($url, ['date_debut' => today()->addDays(90)->toDateString(), 'date_fin' => today()->addDays(92)->toDateString()])->assertStatus(422);
        $this->postJson($url, ['date_debut' => '02/01/2027', 'date_fin' => '03/01/2027'])->assertStatus(422);

        $this->postJson($url, ['date_debut' => $debut, 'date_fin' => $fin])->assertCreated()->assertJsonPath('data.statut', 'en_attente');
        $this->assertSame(0, Garde::count(), 'jamais publié sans modération');
        // Chevauchement avec une déclaration déjà en attente
        $this->postJson($url, ['date_debut' => $fin, 'date_fin' => today()->addDays(12)->toDateString()])->assertStatus(422);

        // Le responsable voit le statut « en attente »
        $this->getJson("/api/v1/mon-etablissement/{$e->id}")->assertJsonPath('data.declarations_garde.0.statut', 'en_attente');

        // Chevauchement avec une garde déjà publiée
        $autre = $this->etab('Autre pharmacie');
        $v = $this->responsable($autre);
        Garde::create(['etablissement_id' => $autre->id, 'date_debut' => today()->addDays(20), 'date_fin' => today()->addDays(26)]);
        $this->enTantQue($v);
        $this->postJson("/api/v1/mon-etablissement/{$autre->id}/garde", ['date_debut' => today()->addDays(25)->toDateString(), 'date_fin' => today()->addDays(28)->toDateString()])->assertStatus(422);
    }

    public function test_seules_les_pharmacies_declarent_une_garde(): void
    {
        $h = $this->etab('Clinique X', [], 'hopital_prive');
        $this->enTantQue($this->responsable($h));
        $this->getJson("/api/v1/mon-etablissement/{$h->id}")->assertOk()->assertJsonPath('data.peut_declarer_garde', false);
        $this->postJson("/api/v1/mon-etablissement/{$h->id}/garde", ['date_debut' => today()->addDay()->toDateString(), 'date_fin' => today()->addDays(3)->toDateString()])->assertForbidden();
    }

    public function test_moderation_d_une_declaration_publie_une_garde_sans_chevauchement(): void
    {
        $e = $this->etab('Pharmacie du Plateau');
        $u = $this->responsable($e);
        $this->enTantQue($u);
        $debut = today()->addDays(2)->toDateString();
        $fin = today()->addDays(8)->toDateString();
        $this->postJson("/api/v1/mon-etablissement/{$e->id}/garde", ['date_debut' => $debut, 'date_fin' => $fin])->assertCreated();
        $d = GardeDeclaration::first();

        $this->enAdmin();
        $this->get('/admin/gardes/declarations')->assertOk()->assertSee('Pharmacie du Plateau')->assertSee('Valider');
        $this->post("/admin/gardes/declarations/{$d->id}/valider")->assertRedirect();

        $garde = Garde::first();
        $this->assertSame($e->id, $garde->etablissement_id);
        $this->assertSame($debut, $garde->date_debut->toDateString());
        $this->assertSame($fin, $garde->date_fin->toDateString());
        $this->assertSame('validee', $d->fresh()->statut);
        $this->assertSame($garde->id, $d->fresh()->garde_id);
        $this->assertTrue(JournalAdmin::where('action', 'garde.declaration_validee')->exists());

        // Re-validation : refusée, une seule garde
        $this->post("/admin/gardes/declarations/{$d->id}/valider")->assertRedirect();
        $this->assertSame(1, Garde::count());

        $this->enTantQue($u->fresh());
        $this->getJson("/api/v1/mon-etablissement/{$e->id}")->assertJsonPath('data.declarations_garde.0.statut', 'validee');
    }

    public function test_validation_refusee_si_une_garde_a_ete_publiee_entre_temps(): void
    {
        $e = $this->etab('Pharmacie du Plateau');
        $this->enTantQue($this->responsable($e));
        $this->postJson("/api/v1/mon-etablissement/{$e->id}/garde", ['date_debut' => today()->addDays(2)->toDateString(), 'date_fin' => today()->addDays(5)->toDateString()])->assertCreated();
        // Une garde officielle est importée pour la même période
        Garde::create(['etablissement_id' => $e->id, 'date_debut' => today()->addDays(4), 'date_fin' => today()->addDays(10), 'source' => 'Import']);

        $this->enAdmin();
        $d = GardeDeclaration::first();
        $this->post("/admin/gardes/declarations/{$d->id}/valider")->assertRedirect()->assertSessionHasErrors();
        $this->assertSame(1, Garde::count());
        $this->assertSame('en_attente', $d->fresh()->statut);
    }

    public function test_rejet_d_une_declaration_avec_motif(): void
    {
        $e = $this->etab('Pharmacie du Plateau');
        $u = $this->responsable($e);
        $this->enTantQue($u);
        $this->postJson("/api/v1/mon-etablissement/{$e->id}/garde", ['date_debut' => today()->addDays(2)->toDateString(), 'date_fin' => today()->addDays(5)->toDateString()])->assertCreated();
        $d = GardeDeclaration::first();

        $this->enAdmin();
        $this->post("/admin/gardes/declarations/{$d->id}/rejeter", [])->assertSessionHasErrors('motif_rejet');
        $this->post("/admin/gardes/declarations/{$d->id}/rejeter", ['motif_rejet' => 'Période non conforme au programme officiel'])->assertRedirect();

        $this->assertSame(0, Garde::count());
        $this->assertSame('rejetee', $d->fresh()->statut);
        $this->enTantQue($u->fresh());
        $this->getJson("/api/v1/mon-etablissement/{$e->id}")
            ->assertJsonPath('data.declarations_garde.0.statut', 'rejetee')
            ->assertJsonPath('data.declarations_garde.0.motif_rejet', 'Période non conforme au programme officiel');
    }
}
