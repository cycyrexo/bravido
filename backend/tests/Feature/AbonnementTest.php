<?php

namespace Tests\Feature;

use App\Models\Abonnement;
use App\Models\Complice;
use App\Models\Paiement;
use App\Models\Plan;
use App\Models\User;
use App\Services\Paiement\SandboxProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F19 : abonnement famille + paiement (bac à sable). */
class AbonnementTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private function souscrire(User $u, string $plan = 'famille_mensuel'): string
    {
        Sanctum::actingAs($u);

        return $this->postJson('/api/v1/abonnement/souscrire', ['plan' => $plan, 'fournisseur' => 'sandbox'])->assertCreated()->json('data.reference');
    }

    private function webhook(string $reference, string $statut = 'reussi', ?int $montant = null, ?string $signature = null)
    {
        $corps = json_encode(array_filter(['reference' => $reference, 'statut' => $statut, 'montant' => $montant], fn ($v) => $v !== null));
        $sig = $signature ?? (new SandboxProvider)->signer($corps);

        return $this->call('POST', '/api/v1/paiements/webhook/sandbox', [], [], [], [
            'HTTP_X_BRAVIDO_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $corps);
    }

    public function test_plans_publics_avec_prix_provisoire(): void
    {
        $r = $this->getJson('/api/v1/plans')->assertOk();
        $r->assertJsonPath('data.0.code', 'famille_mensuel')->assertJsonPath('data.0.prix_xof', 1000)->assertJsonPath('data.0.profils_max', 4)->assertJsonPath('data.0.a_confirmer', true);
        $this->assertStringContainsString('gratuites', $r->json('note'));
        Plan::create(['code' => 'cache', 'nom' => 'Caché', 'prix_xof' => 5, 'duree_jours' => 1, 'profils_max' => 1, 'actif' => false]);
        $this->assertCount(1, $this->getJson('/api/v1/plans')->json('data'));
    }

    public function test_souscription_sandbox_puis_webhook_active_l_abonnement(): void
    {
        $u = User::factory()->create();
        $ref = $this->souscrire($u);
        $p = Paiement::where('reference', $ref)->first();
        $this->assertSame('en_attente', $p->statut);
        $this->assertSame(1000, $p->montant);
        $this->assertSame('XOF', $p->devise);

        $this->getJson('/api/v1/abonnement')->assertJsonPath('data.actif', false);

        $this->webhook($ref)->assertOk()->assertJsonPath('statut', 'reussi')->assertJsonPath('deja_traite', false);
        $this->assertSame(1, Abonnement::count());

        $r = $this->getJson('/api/v1/abonnement')->assertOk();
        $r->assertJsonPath('data.actif', true)->assertJsonPath('data.abonnement.role', 'proprietaire')->assertJsonPath('data.abonnement.places_max', 4)
            ->assertJsonPath('data.avantages.support_prioritaire', true);
        $this->assertSame(now()->addDays(30)->toDateString(), $r->json('data.abonnement.fin'));
    }

    public function test_webhook_signature_obligatoire_et_verifiee(): void
    {
        $ref = $this->souscrire(User::factory()->create());
        $this->webhook($ref, 'reussi', null, 'faux')->assertStatus(401);
        $this->webhook($ref, 'reussi', null, '')->assertStatus(401);
        $this->assertSame(0, Abonnement::count());
        $this->assertSame('en_attente', Paiement::first()->statut);

        // Corps modifié après signature : refusé
        $corps = json_encode(['reference' => $ref, 'statut' => 'echoue']);
        $sig = (new SandboxProvider)->signer($corps);
        $this->call('POST', '/api/v1/paiements/webhook/sandbox', [], [], [], ['HTTP_X_BRAVIDO_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], str_replace('echoue', 'reussi', $corps))->assertStatus(401);
        $this->postJson('/api/v1/paiements/webhook/inconnu', [])->assertNotFound();
    }

    public function test_webhook_idempotent_par_reference(): void
    {
        $u = User::factory()->create();
        $ref = $this->souscrire($u);
        $this->webhook($ref)->assertOk();
        $fin = Abonnement::first()->fin;

        $this->webhook($ref)->assertOk()->assertJsonPath('deja_traite', true);
        $this->webhook($ref)->assertOk()->assertJsonPath('deja_traite', true);
        $this->assertSame(1, Abonnement::count());
        $this->assertTrue(Abonnement::first()->fin->equalTo($fin)); // pas de prolongation en rejeu

        // Un échec tardif ne défait pas un paiement réussi
        $this->webhook($ref, 'echoue')->assertOk()->assertJsonPath('statut', 'reussi');
        $this->assertSame('actif', Abonnement::first()->statut);
    }

    public function test_echec_et_montant_incoherent_n_activent_rien(): void
    {
        $u = User::factory()->create();
        $ref = $this->souscrire($u);
        $this->webhook($ref, 'echoue')->assertOk()->assertJsonPath('statut', 'echoue');
        $this->assertSame(0, Abonnement::count());

        $ref2 = $this->souscrire($u);
        $this->webhook($ref2, 'reussi', 1)->assertOk()->assertJsonPath('statut', 'echoue');
        $this->assertSame(0, Abonnement::count());
        $this->webhook('BRV-INCONNU')->assertNotFound();
    }

    public function test_second_paiement_prolonge_et_expiration(): void
    {
        $u = User::factory()->create();
        $this->webhook($this->souscrire($u));
        $this->webhook($this->souscrire($u));
        $this->assertSame(1, Abonnement::count());
        $this->assertSame(now()->addDays(60)->toDateString(), Abonnement::first()->fin->toDateString());

        Abonnement::query()->update(['fin' => now()->subMinute()]);
        $this->assertNull($u->abonnementEnCours());
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/abonnement')->assertJsonPath('data.actif', false);
        Artisan::call('bravido:expirer-abonnements');
        $this->assertSame('expire', Abonnement::first()->statut);

        // Réabonnement après expiration : nouvel abonnement
        $this->webhook($this->souscrire($u));
        $this->assertSame(2, Abonnement::count());
        $this->assertNotNull($u->abonnementEnCours());
    }

    public function test_fournisseurs_reels_non_configures(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach (['orange_money' => 'Orange Money', 'mtn_momo' => 'MTN MoMo', 'wave' => 'Wave'] as $code => $nom) {
            $r = $this->postJson('/api/v1/abonnement/souscrire', ['plan' => 'famille_mensuel', 'fournisseur' => $code])->assertStatus(503);
            $this->assertStringContainsString("{$nom} n'est pas configuré", $r->json('message'));
            $this->assertSame('non_configure', $r->json('code'));
        }
        $this->assertSame(0, Paiement::count()); // aucun paiement fantôme
        $this->postJson("/api/v1/paiements/webhook/wave", [])->assertStatus(503);
        $this->postJson('/api/v1/abonnement/souscrire', ['plan' => 'famille_mensuel', 'fournisseur' => 'paypal'])->assertStatus(422);
        $this->postJson('/api/v1/abonnement/souscrire', ['plan' => 'nexistepas', 'fournisseur' => 'sandbox'])->assertStatus(422);

        $f = collect($this->getJson('/api/v1/abonnement')->json('data.fournisseurs'))->keyBy('code');
        $this->assertTrue($f['sandbox']['disponible']);
        $this->assertFalse($f['wave']['disponible']);
    }

    public function test_sandbox_desactive_en_production(): void
    {
        config(['services.paiement.sandbox' => false]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/abonnement/souscrire', ['plan' => 'famille_mensuel', 'fournisseur' => 'sandbox'])->assertStatus(503);
    }

    public function test_page_sandbox_simuler_succes(): void
    {
        $u = User::factory()->create();
        $ref = $this->souscrire($u);
        $this->get("/paiement/sandbox/{$ref}")->assertOk()->assertSee('Simuler un succès')->assertSee('Simuler un échec')->assertSee('gratuites');
        $this->post("/paiement/sandbox/{$ref}/succes")->assertOk()->assertSee('Paiement simulé réussi');
        $this->assertNotNull($u->abonnementEnCours());
        $this->get('/paiement/sandbox/BRV-INCONNU')->assertNotFound();
        $this->post("/paiement/sandbox/{$ref}/nimporte")->assertNotFound();
    }

    public function test_membres_limite_invitation_acceptation_retrait(): void
    {
        $titulaire = User::factory()->create();
        $this->webhook($this->souscrire($titulaire));
        $proches = User::factory()->count(4)->create();

        // Pas de compte pour ce numéro
        $this->postJson('/api/v1/abonnement/membres', ['telephone' => '0799999999'])->assertStatus(422);

        // Le plan compte 4 profils au total, titulaire compris : 3 membres au maximum
        foreach ($proches->take(3) as $p) {
            $this->postJson('/api/v1/abonnement/membres', ['telephone' => $p->telephone])->assertCreated();
        }
        $this->postJson('/api/v1/abonnement/membres', ['telephone' => $proches[3]->telephone])->assertStatus(422)->assertSee('Limite atteinte', false);
        $this->postJson('/api/v1/abonnement/membres', ['telephone' => $proches[0]->telephone])->assertStatus(409);
        $this->postJson('/api/v1/abonnement/membres', ['telephone' => $titulaire->telephone])->assertStatus(422);

        // Le proche accepte et profite de l'abonnement ; il ne peut pas inviter à son tour
        Sanctum::actingAs($proches[0]);
        $inv = $this->getJson('/api/v1/abonnement/invitations')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->assertNull($proches[0]->abonnementEnCours());
        $this->postJson("/api/v1/abonnement/invitations/{$inv}/accepter")->assertOk();
        $this->getJson('/api/v1/abonnement')->assertJsonPath('data.actif', true)->assertJsonPath('data.abonnement.role', 'membre')->assertJsonPath('data.membres.0.telephone', null);
        $this->postJson('/api/v1/abonnement/membres', ['telephone' => $proches[3]->telephone])->assertForbidden();

        // Un tiers ne peut ni accepter ni retirer
        Sanctum::actingAs($proches[3]);
        $this->postJson("/api/v1/abonnement/invitations/{$inv}/accepter")->assertNotFound();
        $this->deleteJson("/api/v1/abonnement/membres/{$inv}")->assertNotFound();

        // Le titulaire retire un membre, libérant une place ; le membre peut aussi quitter
        Sanctum::actingAs($titulaire);
        $this->deleteJson("/api/v1/abonnement/membres/{$inv}")->assertOk();
        $this->assertNull($proches[0]->fresh()->abonnementEnCours());
        $this->postJson('/api/v1/abonnement/membres', ['telephone' => $proches[3]->telephone])->assertCreated();
    }

    public function test_regle_produit_les_alertes_ne_dependent_jamais_de_l_abonnement(): void
    {
        // Utilisateur SANS abonnement : l'alerte part (push non configuré en test, donc SMS de secours immédiat)
        $user = User::factory()->create(['nom' => 'Koffi']);
        $proche = User::factory()->create();
        $c = new Complice(['nom' => 'P', 'telephone' => $proche->telephone, 'ordre' => 1]);
        $c->statut = 'accepte';
        $c->user_id = $user->id;
        $c->complice_user_id = $proche->id;
        $c->invitation_token = str_repeat('d', 48);
        $c->save();

        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/alertes', ['motif' => 'malaise', 'lat' => 5.36, 'lng' => -4.0])->assertCreated()->json('data.id');
        $this->assertNotNull(\App\Models\AlerteDestinataire::where('alerte_id', $id)->value('sms_envoye_at'));
        $this->postJson("/api/v1/alertes/{$id}/positions", ['lat' => 5.37, 'lng' => -4.0])->assertCreated();
        $this->assertNull($user->abonnementEnCours());

        // Un abonnement expiré ou annulé ne change rien non plus
        $a = new Abonnement;
        $a->user_id = $user->id;
        $a->plan_id = Plan::first()->id;
        $a->statut = 'annule';
        $a->origine = 'manuel';
        $a->debut = now()->subDays(40);
        $a->fin = now()->subDays(10);
        $a->save();
        $this->postJson("/api/v1/alertes/{$id}/cloturer")->assertOk();
        $this->postJson('/api/v1/alertes', ['motif' => 'accident'])->assertCreated();
    }

    public function test_aucun_code_d_envoi_d_alerte_ne_reference_l_abonnement(): void
    {
        $fichiers = array_merge(
            [app_path('Services/AlerteService.php'), app_path('Services/TrajetService.php'), app_path('Services/Notifier.php'), app_path('Services/Push/FcmSender.php')],
            glob(app_path('Jobs/*.php')) ?: [],
            glob(app_path('Services/Sms/*.php')) ?: [],
        );
        foreach ($fichiers as $f) {
            $code = strtolower((string) file_get_contents($f));
            foreach (['abonnement', 'paiement', 'plan::', 'premium'] as $mot) {
                $this->assertStringNotContainsString($mot, $code, basename($f)." ne doit jamais dépendre de « {$mot} » (règle produit : alertes gratuites et identiques pour tous).");
            }
        }
    }

    public function test_historique_etendu_pour_les_abonnes_seulement(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            $a = new \App\Models\Alerte(['motif' => 'autre', 'statut' => 'close']);
            $a->user_id = $user->id;
            $a->lien_token = "tok{$i}".str_repeat('x', 30);
            $a->started_at = now()->subHours($i + 2);
            $a->expires_at = now()->subHours($i + 1);
            $a->statut = 'close';
            $a->save();
        }
        Sanctum::actingAs($user);
        $this->assertCount(20, $this->getJson('/api/v1/alertes')->json('data'));
        $this->webhook($this->souscrire($user));
        $this->assertCount(25, $this->getJson('/api/v1/alertes')->json('data'));
    }

    public function test_back_office_reserve_aux_administrateurs(): void
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);
        $mod = User::factory()->create();
        $mod->forceFill(['role' => 'moderateur', 'statut' => 'actif', 'email' => 'm@test.ci'])->save();
        $this->actingAs($mod);
        $this->get('/admin/abonnements')->assertForbidden();
        $this->post('/admin/abonnements/accorder', [])->assertForbidden();
        $this->get('/admin')->assertDontSee('Abonnements');

        $this->preparer();
        $this->get('/admin/abonnements')->assertOk()->assertSee('Accorder un abonnement');
        $this->get('/admin/abonnements?onglet=paiements')->assertOk();
        $this->get('/admin/abonnements?onglet=plans')->assertOk()->assertSee('famille_mensuel')->assertSee('à confirmer');
    }

    public function test_admin_octroi_annulation_plans_et_journal(): void
    {
        $this->preparer();
        $u = User::factory()->create(['telephone' => '+2250701010101']);

        $this->post('/admin/abonnements/accorder', ['telephone' => '0701010101', 'plan_id' => Plan::first()->id, 'jours' => 10])->assertRedirect();
        $a = Abonnement::first();
        $this->assertSame('manuel', $a->origine);
        $this->assertSame(now()->addDays(10)->toDateString(), $a->fin->toDateString());
        $this->assertNotNull($u->abonnementEnCours());
        $this->assertDatabaseHas('journal_admin', ['action' => 'abonnement.octroi']);
        $this->post('/admin/abonnements/accorder', ['telephone' => '0709999999', 'plan_id' => Plan::first()->id])->assertSessionHasErrors('telephone');

        $this->get('/admin/abonnements?q=0701010101&statut=actif')->assertOk()->assertSee('+2250701010101');
        $this->post("/admin/abonnements/{$a->id}/annuler")->assertRedirect();
        $this->assertSame('annule', $a->fresh()->statut);
        $this->assertNull($u->abonnementEnCours());
        $this->assertDatabaseHas('journal_admin', ['action' => 'abonnement.annulation']);
        $this->post("/admin/abonnements/{$a->id}/annuler")->assertStatus(409);

        // CRUD plans
        $this->post('/admin/abonnements/plans', ['code' => 'annuel', 'nom' => 'Famille annuel', 'prix_xof' => 10000, 'duree_jours' => 365, 'profils_max' => 5, 'actif' => 1])->assertRedirect();
        $plan = Plan::where('code', 'annuel')->first();
        $this->assertFalse($plan->a_confirmer);
        $this->post("/admin/abonnements/plans/{$plan->id}", ['code' => 'annuel', 'nom' => 'Famille annuel+', 'prix_xof' => 9000, 'duree_jours' => 365, 'profils_max' => 6, 'a_confirmer' => 1])->assertRedirect();
        $plan->refresh();
        $this->assertSame(9000, $plan->prix_xof);
        $this->assertFalse($plan->actif);
        $this->assertTrue($plan->a_confirmer);
        $this->post('/admin/abonnements/plans', ['code' => 'annuel', 'nom' => 'x', 'prix_xof' => 1, 'duree_jours' => 1, 'profils_max' => 1])->assertSessionHasErrors('code');
        $this->assertDatabaseHas('journal_admin', ['action' => 'plan.creation']);
    }

    public function test_support_prioritaire_visible_dans_les_messages(): void
    {
        $abonne = User::factory()->create(['nom' => 'Abonnée Test']);
        $this->webhook($this->souscrire($abonne));
        $m = new \App\Models\MessageSupport(['type' => 'suggestion', 'texte' => 'Bonjour']);
        $m->user_id = $abonne->id;
        $m->save();
        $this->preparer();
        $this->get('/admin/messages')->assertOk()->assertSee('Prioritaire');
    }
}
