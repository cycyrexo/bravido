<?php

namespace Tests\Feature;

use App\Models\Complice;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_demande_de_code_normalise_le_numero(): void
    {
        $this->postJson('/api/v1/auth/otp', ['telephone' => '07 07 12 34 56'])
            ->assertOk()
            ->assertJsonPath('telephone', '+2250707123456');

        $this->assertDatabaseHas('otp_codes', ['telephone' => '+2250707123456']);
    }

    public function test_un_seul_code_par_minute(): void
    {
        $this->postJson('/api/v1/auth/otp', ['telephone' => '0707123456'])->assertOk();
        $this->postJson('/api/v1/auth/otp', ['telephone' => '0707123456'])->assertStatus(422);
    }

    public function test_verification_cree_le_compte_et_rattache_les_invitations(): void
    {
        $proche = User::factory()->create();
        $invitation = new Complice(['nom' => 'Awa', 'telephone' => '+2250707123456', 'ordre' => 1]);
        $invitation->user_id = $proche->id;
        $invitation->invitation_token = str_repeat('a', 48);
        $invitation->save();

        $this->creerOtp('+2250707123456', '481516');

        $reponse = $this->postJson('/api/v1/auth/verifier', ['telephone' => '0707123456', 'code' => '481516'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'telephone']])
            ->assertJsonPath('nouveau', true);

        $this->assertSame($reponse->json('user.id'), $invitation->fresh()->complice_user_id);
    }

    public function test_mauvais_code_refuse_puis_bloque_apres_cinq_essais(): void
    {
        $this->creerOtp('+2250707123456', '481516');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/verifier', ['telephone' => '0707123456', 'code' => '000000'])->assertStatus(422);
        }

        // Même le bon code est refusé une fois le quota d'essais atteint
        $this->postJson('/api/v1/auth/verifier', ['telephone' => '0707123456', 'code' => '481516'])->assertStatus(422);
    }

    private function creerOtp(string $telephone, string $code): void
    {
        OtpCode::create([
            'telephone' => $telephone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
        ]);
    }
}
