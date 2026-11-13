<?php

namespace Tests\Feature;

use App\Jobs\NotifierNouvelAudio;
use App\Models\Alerte;
use App\Models\AlerteAudio;
use App\Models\Complice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlerteAudioTest extends TestCase
{
    use RefreshDatabase;

    private function cercleEtAlerte(): array
    {
        $user = User::factory()->create(['nom' => 'Awa']);
        $proche = User::factory()->create();
        $complice = new Complice(['nom' => 'Proche', 'telephone' => $proche->telephone, 'ordre' => 1]);
        $complice->user_id = $user->id;
        $complice->complice_user_id = $proche->id;
        $complice->statut = 'accepte';
        $complice->invitation_token = str_repeat('a', 48);
        $complice->save();

        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/alertes', ['motif' => 'malaise'])->assertCreated()->json('data.id');

        return [$user, $proche, $id];
    }

    private function envoyer(int $alerteId, ?UploadedFile $fichier = null, array $extra = [])
    {
        return $this->post("/api/v1/alertes/{$alerteId}/audio", array_merge([
            'fichier' => $fichier ?? UploadedFile::fake()->create('message.m4a', 120, 'audio/mp4'),
            'duree_s' => 12,
        ], $extra), ['Accept' => 'application/json']);
    }

    public function test_enregistrement_lecture_et_notification(): void
    {
        Storage::fake('local');
        Bus::fake([NotifierNouvelAudio::class]);
        [$user, $proche, $id] = $this->cercleEtAlerte();

        $audioId = $this->envoyer($id)->assertCreated()->assertJsonPath('data.duree_s', 12)->json('data.id');
        Bus::assertDispatched(NotifierNouvelAudio::class);
        $this->assertTrue(Storage::disk('local')->exists(AlerteAudio::find($audioId)->chemin));

        // Le propriétaire et le complice accepté voient la liste et peuvent lire
        $this->getJson("/api/v1/alertes/{$id}")->assertJsonCount(1, 'data.audios');
        $this->get("/api/v1/alertes/{$id}/audio/{$audioId}")->assertOk();

        Sanctum::actingAs($proche);
        $this->getJson("/api/v1/alertes/{$id}")->assertJsonPath('data.audios.0.id', $audioId);
        $this->get("/api/v1/alertes/{$id}/audio/{$audioId}")->assertOk()->assertHeader('Content-Type', 'audio/mp4');
    }

    public function test_autorisations(): void
    {
        Storage::fake('local');
        [$user, $proche, $id] = $this->cercleEtAlerte();
        $audioId = $this->envoyer($id)->assertCreated()->json('data.id');

        // Un complice ne peut pas déposer de message à la place du propriétaire
        Sanctum::actingAs($proche);
        $this->envoyer($id)->assertNotFound();

        // Un inconnu ne peut ni lire ni écrire
        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/v1/alertes/{$id}/audio/{$audioId}", ['Accept' => 'application/json'])->assertNotFound();
        $this->envoyer($id)->assertNotFound();

        // Un complice retiré (refusé) ne peut plus lire
        Complice::where('user_id', $user->id)->update(['statut' => 'refuse']);
        Sanctum::actingAs($proche);
        $this->get("/api/v1/alertes/{$id}/audio/{$audioId}", ['Accept' => 'application/json'])->assertNotFound();

        // Sans authentification
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/alertes/{$id}/audio/{$audioId}")->assertUnauthorized();
    }

    public function test_limites_de_taille_de_type_de_duree_et_de_nombre(): void
    {
        Storage::fake('local');
        [$user, $proche, $id] = $this->cercleEtAlerte();

        // Trop gros (> 1,5 Mo)
        $this->envoyer($id, UploadedFile::fake()->create('gros.m4a', 1600, 'audio/mp4'))->assertStatus(422)->assertJsonValidationErrors('fichier');
        // Pile à la limite : accepté
        $this->envoyer($id, UploadedFile::fake()->create('limite.m4a', 1536, 'audio/mp4'))->assertCreated();
        // Mauvais type
        $this->envoyer($id, UploadedFile::fake()->create('virus.exe', 50, 'application/x-msdownload'))->assertStatus(422);
        $this->envoyer($id, UploadedFile::fake()->create('texte.txt', 5, 'text/plain'))->assertStatus(422);
        // Types acceptés
        foreach (['audio/aac', 'audio/ogg', 'audio/webm'] as $mime) {
            $this->envoyer($id, UploadedFile::fake()->create('a.bin', 10, $mime))->assertCreated();
        }
        // Durée > 30 s
        $this->envoyer($id, null, ['duree_s' => 31])->assertStatus(422)->assertJsonValidationErrors('duree_s');
        // 5 messages max par alerte (4 déjà déposés ci-dessus)
        $this->envoyer($id)->assertCreated();
        $this->envoyer($id)->assertStatus(422);
    }

    public function test_pas_d_envoi_sur_une_alerte_terminee(): void
    {
        Storage::fake('local');
        [$user, $proche, $id] = $this->cercleEtAlerte();
        $this->postJson("/api/v1/alertes/{$id}/cloturer")->assertOk();
        $this->envoyer($id)->assertStatus(409);
    }

    public function test_purge_apres_30_jours(): void
    {
        Storage::fake('local');
        [$user, $proche, $id] = $this->cercleEtAlerte();
        $vieux = $this->envoyer($id)->json('data.id');
        $recent = $this->envoyer($id)->json('data.id');
        $cheminVieux = AlerteAudio::find($vieux)->chemin;
        AlerteAudio::whereKey($vieux)->update(['created_at' => now()->subDays(31)]);

        $this->artisan('bravido:purger')->assertSuccessful();

        $this->assertNull(AlerteAudio::find($vieux));
        $this->assertNotNull(AlerteAudio::find($recent));
        Storage::disk('local')->assertMissing($cheminVieux);
        Storage::disk('local')->assertExists(AlerteAudio::find($recent)->chemin);
    }
}
