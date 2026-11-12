<?php

namespace Tests\Feature;

use App\Models\Complice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplicesAlerteTest extends TestCase
{
    use RefreshDatabase;

    public function test_limite_de_sept_complices(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        for ($i = 1; $i <= 7; $i++) {
            $this->postJson('/api/v1/complices', ['nom' => "Complice {$i}", 'telephone' => '070000000'.$i])->assertCreated();
        }

        $this->postJson('/api/v1/complices', ['nom' => 'De trop', 'telephone' => '0700000008'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    public function test_le_complice_doit_accepter_avant_de_recevoir_les_alertes(): void
    {
        $user = User::factory()->create(['nom' => 'Koffi']);
        $proche = User::factory()->create();

        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/complices', ['nom' => 'Proche', 'telephone' => $proche->telephone])
            ->assertCreated()
            ->json('data.id');

        // Invitation pas encore acceptée : pas d'alerte possible
        $this->postJson('/api/v1/alertes', ['motif' => 'malaise'])->assertStatus(422);

        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/invitations/{$id}/accepter")->assertOk()->assertJsonPath('data.statut', 'accepte');

        Sanctum::actingAs($user);
        $alerte = $this->postJson('/api/v1/alertes', ['motif' => 'malaise', 'lat' => 5.36, 'lng' => -4.0, 'precision_m' => 12])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'active')
            ->json('data');

        // Push non configuré en test : le SMS de secours part immédiatement
        $this->assertDatabaseHas('alerte_destinataires', ['alerte_id' => $alerte['id'], 'destinataire_user_id' => $proche->id]);
        $this->assertNotNull(\App\Models\AlerteDestinataire::where('alerte_id', $alerte['id'])->value('sms_envoye_at'));

        // Le complice voit l'alerte et répond
        Sanctum::actingAs($proche);
        $this->getJson('/api/v1/alertes-recues')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/alertes/{$alerte['id']}")->assertOk()->assertJsonPath('data.position.lat', 5.36);
        $this->postJson("/api/v1/alertes/{$alerte['id']}/reponse", ['reponse' => 'j_arrive'])->assertOk()->assertJsonPath('etat', 'j_arrive');

        // Un inconnu n'y a pas accès
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/alertes/{$alerte['id']}")->assertNotFound();

        // Clôture par la personne protégée
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/alertes/{$alerte['id']}/cloturer")->assertOk()->assertJsonPath('data.statut', 'close');
        $this->postJson("/api/v1/alertes/{$alerte['id']}/positions", ['lat' => 5.36, 'lng' => -4.0])->assertStatus(409);
    }

    public function test_on_ne_peut_pas_accepter_l_invitation_d_un_autre(): void
    {
        $user = User::factory()->create();
        $complice = new Complice(['nom' => 'X', 'telephone' => '+2250700000001', 'ordre' => 1]);
        $complice->user_id = $user->id;
        $complice->invitation_token = str_repeat('b', 48);
        $complice->save();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/invitations/{$complice->id}/accepter")->assertNotFound();
    }
}
