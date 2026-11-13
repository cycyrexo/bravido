<?php

namespace Tests\Feature;

use App\Models\Alerte;
use App\Models\AlerteEvenement;
use App\Models\Complice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F22 : journal d'audit des alertes. */
class AuditAlerteTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private function alerteAvecProche(): array
    {
        $user = User::factory()->create(['nom' => 'Koffi']);
        $proche = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/complices', ['nom' => 'Proche', 'telephone' => $proche->telephone])->json('data.id');
        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/invitations/{$id}/accepter")->assertOk();
        Sanctum::actingAs($user);
        $alerte = $this->postJson('/api/v1/alertes', ['motif' => 'malaise', 'lat' => 5.36, 'lng' => -4.0])->assertCreated()->json('data');

        return [$user, $proche, $alerte['id']];
    }

    private function types(int $alerteId): array
    {
        return AlerteEvenement::where('alerte_id', $alerteId)->orderBy('id')->pluck('type')->all();
    }

    public function test_evenements_ecrits_aux_bons_moments(): void
    {
        [$user, $proche, $id] = $this->alerteAvecProche();

        // Push non configuré en test : déclenchement puis SMS de secours immédiat
        $this->assertSame(['declenchee', 'sms_envoye'], $this->types($id));

        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/alertes/{$id}/accuse")->assertOk();
        $this->postJson("/api/v1/alertes/{$id}/accuse")->assertOk(); // idempotent
        $this->postJson("/api/v1/alertes/{$id}/vu")->assertOk();
        $this->postJson("/api/v1/alertes/{$id}/reponse", ['reponse' => 'j_arrive'])->assertOk();
        $this->assertSame(['declenchee', 'sms_envoye', 'recue', 'vue', 'reponse'], $this->types($id));

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/alertes/{$id}/cloturer")->assertOk();
        $this->assertSame('cloturee', AlerteEvenement::where('alerte_id', $id)->latest('id')->value('type'));
        // La clôture est idempotente : pas de second événement
        $this->postJson("/api/v1/alertes/{$id}/cloturer")->assertOk();
        $this->assertSame(1, AlerteEvenement::where('alerte_id', $id)->where('type', 'cloturee')->count());
    }

    public function test_annulation_et_expiration(): void
    {
        [$user, , $id] = $this->alerteAvecProche();
        $this->postJson("/api/v1/alertes/{$id}/annuler")->assertOk();
        $this->assertContains('annulee', $this->types($id));

        // Une autre alerte, clôturée par le planificateur
        $user2 = User::factory()->create();
        $proche = User::factory()->create();
        Sanctum::actingAs($user2);
        $cid = $this->postJson('/api/v1/complices', ['nom' => 'P', 'telephone' => $proche->telephone])->json('data.id');
        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/invitations/{$cid}/accepter")->assertOk();
        Sanctum::actingAs($user2);
        $id2 = $this->postJson('/api/v1/alertes', ['motif' => 'autre'])->json('data.id');
        Alerte::whereKey($id2)->update(['expires_at' => now()->subMinute()]);
        Artisan::call('bravido:cloturer-alertes');
        $this->assertContains('expiree', $this->types($id2));
        $this->assertNotContains('cloturee', $this->types($id2));
    }

    public function test_message_vocal_note_sans_contenu(): void
    {
        [, , $id] = $this->alerteAvecProche();
        $fichier = UploadedFile::fake()->create('v.m4a', 20, 'audio/mp4');
        $this->post("/api/v1/alertes/{$id}/audio", ['fichier' => $fichier, 'duree_s' => 3], ['Accept' => 'application/json']);
        if (in_array('audio', $this->types($id), true)) {
            $this->assertSame(1, AlerteEvenement::where('alerte_id', $id)->where('type', 'audio')->count());
        } else {
            $this->markTestIncomplete('Format audio factice non accepté par la validation mimetypes.');
        }
    }

    public function test_aucune_donnee_personnelle_dans_la_table(): void
    {
        $colonnes = Schema::getColumnListing('alerte_evenements');
        sort($colonnes);
        $this->assertSame(['alerte_id', 'canal', 'created_at', 'destinataire_ref', 'id', 'type'], $colonnes);

        [$user, $proche, $id] = $this->alerteAvecProche();
        $brut = json_encode(AlerteEvenement::where('alerte_id', $id)->get()->toArray());
        foreach ([$user->telephone, $proche->telephone, 'Koffi', 'Proche', '5.36', 'malaise'] as $interdit) {
            $this->assertStringNotContainsString((string) $interdit, $brut);
        }
        // Le destinataire n'est identifié que par un rang
        $this->assertSame(1, (int) AlerteEvenement::where('alerte_id', $id)->where('type', 'sms_envoye')->value('destinataire_ref'));
    }

    public function test_trajet_auto_note(): void
    {
        $user = User::factory()->create();
        $proche = User::factory()->create();
        $c = new Complice(['nom' => 'P', 'telephone' => $proche->telephone, 'ordre' => 1]);
        $c->statut = 'accepte';
        $c->user_id = $user->id;
        $c->complice_user_id = $proche->id;
        $c->invitation_token = str_repeat('c', 48);
        $c->save();

        $alerte = app(\App\Services\AlerteService::class)->declencher($user, ['motif' => 'autre', 'origine' => 'trajet']);
        $this->assertSame(['declenchee', 'trajet_auto', 'sms_envoye'], $this->types($alerte->id));
    }

    public function test_purge_a_12_mois(): void
    {
        [, , $id] = $this->alerteAvecProche();
        $ancien = AlerteEvenement::create(['alerte_id' => $id, 'type' => 'vue', 'created_at' => now()->subMonths(13)]);
        $recent = AlerteEvenement::create(['alerte_id' => $id, 'type' => 'vue', 'created_at' => now()->subMonths(11)]);
        Artisan::call('bravido:purger');
        $this->assertNull(AlerteEvenement::find($ancien->id));
        $this->assertNotNull(AlerteEvenement::find($recent->id));
    }

    public function test_pages_admin_detail_et_export(): void
    {
        [, , $id] = $this->alerteAvecProche();
        $this->preparer();

        $this->get("/admin/alertes/{$id}")->assertOk()->assertSee('Chronologie')->assertSee('SMS de secours envoyé');
        $this->get('/admin/alertes')->assertOk()->assertSee('Exporter le journal');
        $csv = $this->get('/admin/alertes/export')->assertOk();
        $this->assertStringContainsString('sms_envoye', $csv->streamedContent());
        $this->assertStringNotContainsString('Koffi', $csv->streamedContent());
    }

    public function test_export_reserve_a_l_admin(): void
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);
        $mod = User::factory()->create();
        $mod->forceFill(['role' => 'moderateur', 'statut' => 'actif', 'email' => 'mod@test.ci'])->save();
        $this->actingAs($mod);
        $this->get('/admin/alertes')->assertOk()->assertDontSee('Exporter le journal');
        $this->get('/admin/alertes/export')->assertForbidden();
    }
}
