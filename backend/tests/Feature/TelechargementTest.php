<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TelechargementTest extends TestCase
{
    private string $dossier;

    /** @var array<string, string> contenu des vrais fichiers sauvegardés pendant le test */
    private array $sauvegarde = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dossier = storage_path('app/telechargement');
        File::ensureDirectoryExists($this->dossier);
        // Ne jamais détruire un vrai APK déposé sur la machine : on le met de côté puis on le restaure.
        foreach (["bravido-test.apk", "bravido-test.apk.json"] as $nom) {
            if (is_file($this->dossier.'/'.$nom)) {
                $this->sauvegarde[$nom] = file_get_contents($this->dossier.'/'.$nom);
            }
        }
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        foreach ($this->sauvegarde as $nom => $contenu) {
            File::put($this->dossier.'/'.$nom, $contenu);
        }
        parent::tearDown();
    }

    private function nettoyer(): void
    {
        File::delete([$this->dossier.'/bravido-test.apk', $this->dossier.'/bravido-test.apk.json']);
    }

    private function deposer(): void
    {
        File::put($this->dossier.'/bravido-test.apk', 'PK-faux-apk-pour-test');
        File::put($this->dossier.'/bravido-test.apk.json', json_encode([
            'version' => '1.0.0+1',
            'build_date' => '2026-11-13T10:00:00+00:00',
            'size_bytes' => 21,
            'sha256' => str_repeat('ab', 32),
            'api_url' => 'https://bravido.clikgrouptech.com/api/v1',
            'signed_with' => 'debug key',
        ]));
    }

    public function test_page_sans_apk_affiche_message_et_reste_publique(): void
    {
        $this->get('/telecharger')
            ->assertOk()
            ->assertSee("L'APK n'est pas encore disponible", false)
            ->assertDontSee('/telecharger/bravido.apk', false)
            ->assertSee('Version iPhone', false)
            ->assertSee('noindex', false);
    }

    public function test_page_avec_apk_affiche_infos_bouton_et_qr(): void
    {
        $this->deposer();

        $this->get('/telecharger')
            ->assertOk()
            ->assertSee("Télécharger l'application (Android)", false)
            ->assertSee('/telecharger/bravido.apk', false)
            ->assertSee('1.0.0+1', false)
            ->assertSee('13/11/2026', false)
            ->assertSee(str_repeat('ab', 32), false)
            ->assertSee('Version de test', false)
            ->assertSee('Autoriser les sources inconnues', false)
            ->assertSee('<svg', false)
            ->assertDontSee('<?xml', false)
            ->assertDontSee('cdn.', false);
    }

    public function test_qr_present_meme_sans_apk(): void
    {
        $this->get('/telecharger')->assertOk()->assertSee('<svg', false);
    }

    public function test_telechargement_404_sans_apk(): void
    {
        $this->get('/telecharger/bravido.apk')->assertNotFound();
    }

    public function test_telechargement_entetes_et_contenu(): void
    {
        $this->deposer();

        $r = $this->get('/telecharger/bravido.apk');
        $r->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('bravido-test.apk', $r->headers->get('Content-Disposition'));
        $this->assertSame('PK-faux-apk-pour-test', file_get_contents($r->baseResponse->getFile()->getPathname()));
    }

    public function test_aucune_authentification_requise(): void
    {
        $this->deposer();

        $this->assertGuest();
        $this->get('/telecharger')->assertOk();
        $this->get('/telecharger/bravido.apk')->assertOk();
    }
}
