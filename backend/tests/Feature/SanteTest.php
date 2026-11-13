<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SanteSysteme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F23 : supervision (GET /sante, battement du planificateur, carte du tableau de bord). */
class SanteTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    public function test_sante_publique_et_minimale(): void
    {
        Artisan::call('bravido:heartbeat');
        $r = $this->getJson('/api/v1/sante')->assertOk();
        $r->assertJsonPath('status', 'ok')->assertJsonPath('db', true)->assertJsonPath('scheduler', true);
        // Minimal : aucune clé interne (compteurs, chemins, versions)
        $this->assertSame(['db', 'queue', 'scheduler', 'status', 'time'], collect($r->json())->keys()->sort()->values()->all());
    }

    public function test_heartbeat_absent_ou_trop_ancien_donne_alerte(): void
    {
        $sante = app(SanteSysteme::class);
        Cache::forget(SanteSysteme::CLE_HEARTBEAT);
        $this->assertFalse($sante->planificateur()['ok']);
        $this->getJson('/api/v1/sante')->assertOk()->assertJsonPath('status', 'degrade')->assertJsonPath('scheduler', false);

        Cache::forever(SanteSysteme::CLE_HEARTBEAT, now()->subSeconds(200)->timestamp);
        $this->assertFalse($sante->planificateur()['ok']);

        Cache::forever(SanteSysteme::CLE_HEARTBEAT, now()->subSeconds(100)->timestamp);
        $this->assertTrue($sante->planificateur()['ok']);

        Artisan::call('bravido:heartbeat');
        $this->assertTrue($sante->planificateur()['ok']);
    }

    public function test_file_d_attente_bloquee_au_dela_de_120_s(): void
    {
        config(['queue.default' => 'database']);
        $sante = app(SanteSysteme::class);
        $this->assertTrue($sante->file()['ok']);

        $ligne = fn (int $age) => [
            'queue' => 'alertes', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subSeconds($age)->timestamp, 'created_at' => now()->subSeconds($age)->timestamp,
        ];
        DB::table('jobs')->insert($ligne(30));
        $this->assertTrue($sante->file()['ok']);

        DB::table('jobs')->insert($ligne(200));
        $f = $sante->file();
        $this->assertFalse($f['ok']);
        $this->assertStringContainsString('2 en attente', $f['detail']);

        // Un travail réservé (en cours de traitement) ne compte pas comme bloqué
        DB::table('jobs')->truncate();
        DB::table('jobs')->insert(['reserved_at' => now()->timestamp] + $ligne(500));
        $this->assertTrue($sante->file()['ok']);
    }

    public function test_travaux_en_echec_comptes(): void
    {
        $sante = app(SanteSysteme::class);
        $this->assertTrue($sante->echecs()['ok']);
        DB::table('failed_jobs')->insert([
            'uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);
        $this->assertFalse($sante->echecs()['ok']);
    }

    public function test_fcm_non_configure_et_disque(): void
    {
        config(['services.fcm.project_id' => '', 'services.fcm.credentials' => '']);
        $this->assertFalse(app(SanteSysteme::class)->fcm()['ok']);

        config(['services.fcm.project_id' => 'p', 'services.fcm.credentials' => __FILE__]);
        $this->assertTrue(app(SanteSysteme::class)->fcm()['ok']);

        $this->assertArrayHasKey('detail', app(SanteSysteme::class)->disque());
    }

    public function test_carte_sante_sur_le_tableau_de_bord_admin_seulement(): void
    {
        $this->preparer();
        $this->get('/admin')->assertOk()->assertSee('Santé du système')->assertSee('Planificateur');

        $mod = User::factory()->create();
        $mod->forceFill(['role' => 'moderateur', 'statut' => 'actif', 'email' => 'mod2@test.ci'])->save();
        $this->actingAs($mod);
        $this->get('/admin')->assertOk()->assertDontSee('Santé du système');
    }
}
