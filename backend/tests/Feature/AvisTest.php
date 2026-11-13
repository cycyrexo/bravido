<?php

namespace Tests\Feature;

use App\Models\Avis;
use App\Models\User;
use App\Services\AvisModeration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F13 : avis et notes avec pré-modération. */
class AvisTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private function etabPharmacie()
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);

        return $this->etab('Pharmacie Test');
    }

    private function approuver(Avis $a): void
    {
        $a->forceFill(['statut' => 'approuve', 'modere_at' => now()])->save();
    }

    public function test_un_seul_avis_par_utilisateur_puis_modification_et_suppression(): void
    {
        $e = $this->etabPharmacie();
        $user = User::factory()->create(['nom' => 'Awa Traoré']);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 4, 'commentaire' => 'Accueil rapide'])
            ->assertCreated()->assertJsonPath('data.statut', 'en_attente');
        $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 5])->assertStatus(409);

        $this->putJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 2, 'commentaire' => 'Finalement long'])
            ->assertOk()->assertJsonPath('data.note', 2);
        $this->assertSame(1, Avis::count());

        $this->getJson('/api/v1/mes-avis')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.etablissement.nom', 'Pharmacie Test');

        $this->deleteJson("/api/v1/etablissements/{$e->id}/mon-avis")->assertOk();
        $this->assertSame(0, Avis::count());
        $this->putJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 3])->assertNotFound();
    }

    public function test_limites_de_validation(): void
    {
        $e = $this->etabPharmacie();
        Sanctum::actingAs(User::factory()->create());
        $url = "/api/v1/etablissements/{$e->id}/mon-avis";

        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson($url, ['note' => 0])->assertStatus(422);
        $this->postJson($url, ['note' => 6])->assertStatus(422);
        $this->postJson($url, ['note' => 3, 'commentaire' => str_repeat('a', 501)])->assertStatus(422)->assertJsonValidationErrors('commentaire');
        $this->postJson($url, ['note' => 3, 'commentaire' => str_repeat('a', 500)])->assertCreated();
    }

    public function test_connexion_obligatoire_pour_ecrire(): void
    {
        $e = $this->etabPharmacie();
        $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 5])->assertUnauthorized();
        $this->getJson("/api/v1/etablissements/{$e->id}/avis")->assertOk(); // lecture publique
    }

    public function test_pas_d_avis_sur_les_categories_d_urgence(): void
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);
        $secours = $this->etab('Caserne GSPM', [], 'centre_secours');
        $fga = $this->etab('Bureau FGA', [], 'bureau_fga');
        Sanctum::actingAs(User::factory()->create());

        foreach ([$secours, $fga] as $e) {
            $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 5])->assertForbidden();
            $this->getJson("/api/v1/etablissements/{$e->id}/avis")->assertOk()->assertJsonPath('meta.actif', false)->assertJsonCount(0, 'data');
            $this->getJson("/api/v1/etablissements/{$e->id}")->assertOk()->assertJsonMissingPath('data.avis');
        }
    }

    public function test_seuls_les_avis_approuves_sont_publics_avec_moyenne_et_nom_abrege(): void
    {
        $e = $this->etabPharmacie();
        $a = User::factory()->create(['nom' => 'awa traoré']);
        $b = User::factory()->create(['nom' => 'Koffi']);
        $c = User::factory()->create(['nom' => null]);
        $d = User::factory()->create(['nom' => 'En Attente']);

        foreach ([[$a, 5, 'approuve'], [$b, 3, 'approuve'], [$c, 4, 'approuve'], [$d, 1, 'en_attente']] as [$u, $note, $statut]) {
            Sanctum::actingAs($u);
            $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => $note, 'commentaire' => "Avis {$note}"])->assertCreated();
        }
        Avis::whereIn('note', [5, 3, 4])->update(['statut' => 'approuve']);

        auth()->forgetGuards();
        $r = $this->getJson("/api/v1/etablissements/{$e->id}/avis")->assertOk();
        $r->assertJsonPath('meta.total', 3)->assertJsonPath('meta.moyenne', 4)->assertJsonCount(3, 'data');
        $auteurs = collect($r->json('data'))->pluck('auteur')->all();
        $this->assertContains('Awa T.', $auteurs);
        $this->assertContains('Koffi', $auteurs);
        $this->assertContains('Utilisateur BRAVIDO', $auteurs);
        $this->assertStringNotContainsString('Attente', json_encode($r->json()));
        $this->assertStringNotContainsString($a->telephone, json_encode($r->json()));

        // La fiche expose la moyenne et le nombre
        $this->getJson("/api/v1/etablissements/{$e->id}")->assertJsonPath('data.avis.nombre', 3)->assertJsonPath('data.avis.moyenne', 4);

        // L'auteur voit son avis en attente (et lui seul)
        Sanctum::actingAs($d);
        $this->getJson("/api/v1/etablissements/{$e->id}/avis")->assertJsonPath('meta.mon_avis.statut', 'en_attente')->assertJsonPath('meta.total', 3);
    }

    public function test_signalement_remet_l_avis_en_moderation(): void
    {
        $e = $this->etabPharmacie();
        $auteur = User::factory()->create();
        Sanctum::actingAs($auteur);
        $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 1, 'commentaire' => 'Nul'])->assertCreated();
        $avis = Avis::first();
        $this->approuver($avis);

        $lecteur = User::factory()->create();
        Sanctum::actingAs($lecteur);
        $this->postJson("/api/v1/avis/{$avis->id}/signaler", ['motif' => 'Propos injurieux'])->assertOk();
        $avis->refresh();
        $this->assertSame('en_attente', $avis->statut);
        $this->assertContains('signale', $avis->drapeaux);
        $this->getJson("/api/v1/etablissements/{$e->id}/avis")->assertJsonPath('meta.total', 0);

        // Un avis non publié ne peut pas être signalé ; on ne signale pas le sien
        $this->postJson("/api/v1/avis/{$avis->id}/signaler")->assertNotFound();
        $this->approuver($avis);
        Sanctum::actingAs($auteur);
        $this->postJson("/api/v1/avis/{$avis->id}/signaler")->assertForbidden();
    }

    public function test_reponse_du_responsable_moderee(): void
    {
        $e = $this->etabPharmacie();
        $resp = User::factory()->create();
        $e->forceFill(['responsable_id' => $resp->id])->save();
        $auteur = User::factory()->create();
        Sanctum::actingAs($auteur);
        $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 3, 'commentaire' => 'Moyen'])->assertCreated();
        $avis = Avis::first();

        // Réponse impossible tant que l'avis n'est pas publié
        Sanctum::actingAs($resp);
        $this->postJson("/api/v1/mon-etablissement/avis/{$avis->id}/reponse", ['texte' => 'Merci'])->assertStatus(409);
        $this->approuver($avis);

        // Un tiers ne peut pas répondre
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/mon-etablissement/avis/{$avis->id}/reponse", ['texte' => 'Merci'])->assertForbidden();

        Sanctum::actingAs($resp);
        $this->getJson("/api/v1/mon-etablissement/{$e->id}/avis")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/mon-etablissement/avis/{$avis->id}/reponse", ['texte' => str_repeat('a', 501)])->assertStatus(422);
        $this->postJson("/api/v1/mon-etablissement/avis/{$avis->id}/reponse", ['texte' => 'Merci pour votre retour'])->assertCreated()->assertJsonPath('data.reponse_statut', 'en_attente');

        // Pas publique tant qu'elle n'est pas validée
        auth()->forgetGuards();
        $this->getJson("/api/v1/etablissements/{$e->id}/avis")->assertJsonPath('data.0.reponse', null);

        // Modération au back-office
        $this->preparer();
        $this->get('/admin/avis?onglet=reponses')->assertOk()->assertSee('Merci pour votre retour');
        $this->post("/admin/avis/{$avis->id}/reponse/valider")->assertRedirect();
        auth()->forgetGuards();
        $this->getJson("/api/v1/etablissements/{$e->id}/avis")->assertJsonPath('data.0.reponse.texte', 'Merci pour votre retour');

        // Une seule réponse : en renvoyer une remplace la précédente et repasse en modération
        Sanctum::actingAs($resp);
        $this->postJson("/api/v1/mon-etablissement/avis/{$avis->id}/reponse", ['texte' => 'Version corrigée'])->assertCreated();
        $this->assertSame(1, Avis::whereNotNull('reponse')->count());
        $this->assertSame('en_attente', $avis->fresh()->reponse_statut);
    }

    public function test_le_responsable_ne_note_pas_son_propre_etablissement(): void
    {
        $e = $this->etabPharmacie();
        $resp = User::factory()->create();
        $e->forceFill(['responsable_id' => $resp->id])->save();
        Sanctum::actingAs($resp);
        $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 5])->assertForbidden();
    }

    public function test_moderation_back_office(): void
    {
        $e = $this->etabPharmacie();
        $u = User::factory()->create(['nom' => 'Awa']);
        Sanctum::actingAs($u);
        $this->postJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 1, 'commentaire' => 'Le Dr Kouassi est un voleur, appelez le 0701020304'])->assertCreated();
        $avis = Avis::first();
        $this->assertEqualsCanonicalizing(['telephone', 'personne_nommee', 'accusation'], $avis->drapeaux);

        $this->preparer();
        $this->get('/admin/avis')->assertOk()
            ->assertSee('<mark>', false)->assertSee('Accusation visant une personne nommée')->assertSee('Pharmacie Test');
        $this->get('/admin')->assertSee('Avis'); // badge dans le menu

        // Rejet : motif obligatoire
        $this->post("/admin/avis/{$avis->id}/rejeter", [])->assertSessionHasErrors('motif_rejet');
        $this->post("/admin/avis/{$avis->id}/rejeter", ['motif_rejet' => 'Accusation nominative'])->assertRedirect();
        $this->assertSame('rejete', $avis->fresh()->statut);
        $this->assertDatabaseHas('journal_admin', ['action' => 'avis.rejet']);

        Sanctum::actingAs($u);
        $this->getJson('/api/v1/mes-avis')->assertJsonPath('data.0.motif_rejet', 'Accusation nominative');

        // L'auteur corrige : retour en modération puis validation
        $this->putJson("/api/v1/etablissements/{$e->id}/mon-avis", ['note' => 2, 'commentaire' => 'Attente trop longue'])->assertOk()->assertJsonPath('data.statut', 'en_attente');
        $this->actingAs(User::where('role', 'admin')->first());
        $this->post("/admin/avis/{$avis->id}/valider")->assertRedirect();
        $this->assertSame('approuve', $avis->fresh()->statut);
        $this->getJson("/api/v1/etablissements/{$e->id}/avis")->assertJsonPath('meta.total', 1);
    }

    public function test_detection_automatique_des_contenus_a_examiner(): void
    {
        $m = new AvisModeration();
        $this->assertSame([], $m->analyser('Accueil agréable, service rapide.'));
        $this->assertContains('telephone', $m->analyser('Appelez-moi au +225 07 01 02 03 04'));
        $this->assertContains('lien', $m->analyser('voir www.exemple.ci'));
        $this->assertContains('insulte', $m->analyser('Quel connard à l\'accueil'));
        $this->assertNotContains('insulte', $m->analyser('Le confort est correct')); // « con » seulement en mot entier
        $this->assertContains('personne_nommee', $m->analyser('Le Dr Konan était aimable'));
        $this->assertNotContains('accusation', $m->analyser('Le Dr Konan était aimable'));
        $this->assertContains('accusation', $m->analyser('Dr Konan est un escroc'));
        $this->assertContains('donnee_medicale', $m->analyser('Mon diagnostic de diabète a été annoncé'));
        $this->assertStringContainsString('<mark>', $m->surligner('Dr Konan escroc'));
        $this->assertStringNotContainsString('<script>', $m->surligner('<script>alert(1)</script> escroc'));
    }

    public function test_page_moderation_reservee_au_personnel(): void
    {
        $this->etabPharmacie();
        $this->actingAs(User::factory()->create());
        $this->get('/admin/avis')->assertRedirect();
    }
}
