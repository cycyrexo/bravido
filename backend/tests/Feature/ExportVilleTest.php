<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\VilleExportController;
use App\Models\Etablissement;
use App\Models\Ville;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

/** F20 : export hors ligne d'une ville (version, pagination, gzip). */
class ExportVilleTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private function ville(): Ville
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);

        return Ville::where('actif', true)->first();
    }

    public function test_export_ne_contient_que_les_fiches_publiees_de_la_ville(): void
    {
        $ville = $this->ville();
        $a = $this->etab('Pharmacie A');
        $this->etab('Brouillon', ['statut' => 'brouillon']);
        $a->syncServices(['Vaccination']);

        $r = $this->getJson("/api/v1/villes/{$ville->id}/export")->assertOk();
        $noms = collect($r->json('data'))->pluck('nom')->all();
        $this->assertContains('Pharmacie A', $noms);
        $this->assertNotContains('Brouillon', $noms);
        $r->assertJsonPath('meta.ville.id', $ville->id)->assertJsonStructure(['meta' => ['version', 'total', 'page', 'pages', 'genere_at']]);

        $ligne = collect($r->json('data'))->firstWhere('nom', 'Pharmacie A');
        $this->assertSame(['Vaccination'], $ligne['services']);
        $this->assertSame($ville->id, $ligne['ville_id']);
        $this->assertTrue($ligne['position_approx']);
        // Compact : pas de clés nulles
        $this->assertArrayNotHasKey('email', $ligne);
        // Aucune donnée privée
        $this->assertArrayNotHasKey('responsable_user_id', $ligne);
    }

    public function test_version_stable_puis_modifiee_si_les_donnees_changent(): void
    {
        $ville = $this->ville();
        $e = $this->etab('Pharmacie B');

        $v1 = $this->getJson("/api/v1/villes/{$ville->id}/export")->json('meta.version');
        $v1bis = $this->getJson("/api/v1/villes/{$ville->id}/export")->json('meta.version');
        $this->assertSame($v1, $v1bis);

        // Même version fournie : rien à retélécharger
        $this->getJson("/api/v1/villes/{$ville->id}/export?version={$v1}")
            ->assertOk()->assertJsonPath('a_jour', true)->assertJsonMissingPath('data');

        // ETag / If-None-Match
        $this->getJson("/api/v1/villes/{$ville->id}/export", ['If-None-Match' => '"'.$v1.'"'])->assertJsonPath('a_jour', true);

        // Une fiche modifiée change la version
        $this->travel(2)->minutes();
        $e->forceFill(['nom' => 'Pharmacie B bis'])->save();
        $v2 = VilleExportController::version($ville);
        $this->assertNotSame($v1, $v2);

        // Ajout d'un service : version modifiée aussi
        $e->syncServices(['Radiologie']);
        $this->assertNotSame($v2, VilleExportController::version($ville));

        // Ancienne version fournie : export complet
        $this->getJson("/api/v1/villes/{$ville->id}/export?version={$v1}")->assertJsonPath('data.0.nom', 'Pharmacie B bis');
    }

    public function test_pagination(): void
    {
        $ville = $this->ville();
        for ($i = 1; $i <= 120; $i++) {
            $this->etab("Etab {$i}");
        }
        $p1 = $this->getJson("/api/v1/villes/{$ville->id}/export?par_page=50&page=1")->assertOk();
        $p3 = $this->getJson("/api/v1/villes/{$ville->id}/export?par_page=50&page=3")->assertOk();
        $this->assertCount(50, $p1->json('data'));
        $this->assertCount(20, $p3->json('data'));
        $p1->assertJsonPath('meta.pages', 3)->assertJsonPath('meta.total', 120);
        $this->getJson("/api/v1/villes/{$ville->id}/export?par_page=10")->assertStatus(422);
    }

    public function test_gzip_si_le_client_l_accepte(): void
    {
        $ville = $this->ville();
        $this->etab('Pharmacie Gzip');
        $r = $this->get("/api/v1/villes/{$ville->id}/export", ['Accept-Encoding' => 'gzip']);
        $r->assertOk()->assertHeader('Content-Encoding', 'gzip');
        $json = json_decode(gzdecode($r->getContent()), true);
        $this->assertSame('Pharmacie Gzip', $json['data'][0]['nom']);
    }

    public function test_ville_inactive_ou_inconnue(): void
    {
        $ville = $this->ville();
        $this->getJson('/api/v1/villes/99999/export')->assertNotFound();
        $ville->forceFill(['actif' => false])->save();
        $this->getJson("/api/v1/villes/{$ville->id}/export")->assertNotFound();
    }

    public function test_les_synonymes_dart_sont_identiques_a_ceux_du_serveur(): void
    {
        $fichier = base_path('../mobile/lib/core/synonymes.dart');
        if (! is_file($fichier)) {
            $this->markTestSkipped('Application mobile absente.');
        }
        $source = file_get_contents($fichier);
        foreach (\App\Services\Recherche::SYNONYMES as $mot => $_) {
            $this->assertStringContainsString("'{$mot}':", $source, "Synonyme « {$mot} » absent de synonymes.dart (régénérer le fichier).");
        }
        $this->assertSame(count(\App\Services\Recherche::SYNONYMES), preg_match_all("/^\s*'[a-z.]+': \{/m", $source));
        $this->assertTrue(Etablissement::query()->getModel() instanceof Etablissement);
    }
}
