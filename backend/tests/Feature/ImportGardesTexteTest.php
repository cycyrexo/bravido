<?php

namespace Tests\Feature;

use App\Models\Garde;
use App\Models\ImportLot;
use App\Models\Ville;
use App\Services\Garde\ExtracteurTexte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

class ImportGardesTexteTest extends TestCase
{
    use AdminTestHelpers;
    use RefreshDatabase;

    private int $villeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparer();
        $this->villeId = Ville::where('nom', 'Abidjan')->value('id');
    }

    private function envoyer(array $extra = []): TestResponse
    {
        return $this->post('/admin/gardes/import', $extra + [
            'ville_id' => $this->villeId, 'date_debut' => '2026-11-14', 'date_fin' => '2026-11-20',
        ]);
    }

    private function lot(): ImportLot
    {
        return ImportLot::latest('id')->firstOrFail();
    }

    private function ligne(ImportLot $lot, int $n): array
    {
        return collect($lot->lignes)->firstWhere('ligne', $n);
    }

    public function test_texte_colle_statuts_sans_publication_automatique(): void
    {
        $paix = $this->etab('PHARMACIE DE LA PAIX COCODY');
        $lagunes = $this->etab('Pharmacie Les Lagunes', ['tel1' => '+22527224455']);
        $this->etab('Pharmacie du Centre', [], 'pharmacie', 'Cocody');
        $this->etab('Pharmacie du Centre', [], 'pharmacie', 'Marcory');

        $texte = "PROGRAMME DE GARDE DU 14 AU 20 NOVEMBRE\n1. Pharmacie de la Paix\nPhcie Les Lagunes - 27 22 44 55\nPharmacie du Centre\nPharmacie Inconnue de Nulle Part\n";
        $this->envoyer(['texte' => $texte])->assertRedirect();

        $lot = $this->lot();
        $this->assertSame('Texte collé', $lot->fichier_nom);
        $this->assertSame('apercu', $lot->statut);

        $this->assertSame('erreur', $this->ligne($lot, 1)['statut'], 'titre du programme');
        $this->assertSame('ok', $this->ligne($lot, 2)['statut']);
        $this->assertSame($paix->id, $this->ligne($lot, 2)['etablissement_id']);
        $this->assertSame('ok', $this->ligne($lot, 3)['statut']);
        $this->assertSame($lagunes->id, $this->ligne($lot, 3)['etablissement_id']);

        $centre = $this->ligne($lot, 4);
        $this->assertSame('a_verifier', $centre['statut'], 'homonymes : à confirmer');
        $this->assertNull($centre['etablissement_id']);
        $this->assertCount(2, $centre['candidats']);
        $this->assertSame('erreur', $this->ligne($lot, 5)['statut']);

        $this->assertSame(2, $lot->nb_valides);

        // Publication : seulement les lignes sûres
        $this->post("/admin/gardes/lots/{$lot->id}/publier")->assertRedirect();
        $this->assertSame(2, Garde::count());
        $this->assertEqualsCanonicalizing([$paix->id, $lagunes->id], Garde::pluck('etablissement_id')->all());
        $this->assertSame('2026-11-14', Garde::first()->date_debut->toDateString());
    }

    public function test_correction_manuelle_puis_publication(): void
    {
        $this->etab('Pharmacie de la Paix');
        $cocody = $this->etab('Pharmacie du Centre', [], 'pharmacie', 'Cocody');
        $this->etab('Pharmacie du Centre', [], 'pharmacie', 'Marcory');
        $autre = $this->etab('Pharmacie Étoile du Sud', [], 'pharmacie', 'Marcory');

        $this->envoyer(['texte' => "Pharmacie de la Paix\nPharmacie du Centre\nLigne a ignorer ici"])->assertRedirect();
        $lot = $this->lot();
        $this->assertSame(1, $lot->nb_valides);

        $this->post("/admin/gardes/lots/{$lot->id}/correspondances", ['choix' => [
            1 => '', // ignorer la ligne « ok »
            2 => (string) $cocody->id, // confirmer le homonyme
            3 => "Pharmacie Étoile du Sud (Marcory) #{$autre->id}", // recherche libre
        ]])->assertRedirect();

        $lot->refresh();
        $this->assertSame('erreur', $this->ligne($lot, 1)['statut']);
        $this->assertTrue($this->ligne($lot, 1)['ignore']);
        $this->assertSame('ok', $this->ligne($lot, 2)['statut']);
        $this->assertTrue($this->ligne($lot, 2)['confirme']);
        $this->assertSame($autre->id, $this->ligne($lot, 3)['etablissement_id']);
        $this->assertSame(2, $lot->nb_valides);

        $this->post("/admin/gardes/lots/{$lot->id}/publier");
        $this->assertEqualsCanonicalizing([$cocody->id, $autre->id], Garde::pluck('etablissement_id')->all());
    }

    public function test_choix_invalide_jamais_publie_et_doublon_detecte(): void
    {
        $paix = $this->etab('Pharmacie de la Paix');
        $this->envoyer(['texte' => "Pharmacie de la Paix\nPhcie de la Paix\nAutre ligne"])->assertRedirect();
        $lot = $this->lot();

        $this->assertSame('doublon', $this->ligne($lot, 2)['statut']);

        $this->post("/admin/gardes/lots/{$lot->id}/correspondances", ['choix' => [1 => (string) $paix->id, 2 => '', 3 => '999999']])
            ->assertRedirect();
        $lot->refresh();
        $this->assertSame('erreur', $this->ligne($lot, 3)['statut']);
        $this->assertSame(1, $lot->nb_valides);

        // Une garde existante sur la période rend la ligne « doublon » à la correction
        Garde::create(['etablissement_id' => $paix->id, 'date_debut' => '2026-11-15', 'date_fin' => '2026-11-16']);
        $this->post("/admin/gardes/lots/{$lot->id}/correspondances", ['choix' => [1 => (string) $paix->id]]);
        $lot->refresh();
        $this->assertSame('doublon', $this->ligne($lot, 1)['statut']);
        $this->assertSame(0, $lot->nb_valides);
    }

    public function test_texte_libre_ne_prend_jamais_un_nombre_pour_un_identifiant(): void
    {
        $this->etab('Pharmacie de la Paix');
        $this->envoyer(['texte' => "Pharmacie de la Paix\nPharmacie Les Lagunes 2"]);
        $lot = $this->lot();

        $this->post("/admin/gardes/lots/{$lot->id}/correspondances", ['choix' => [1 => '', 2 => 'Pharmacie Les Lagunes 2']]);
        $lot->refresh();
        $this->assertSame(0, $lot->nb_valides, 'un texte libre non résolu ignore la ligne');
        $this->assertNull($this->ligne($lot, 2)['etablissement_id']);
    }

    public function test_fichier_txt_liste_libre_et_txt_csv_conserve_le_chemin_tableur(): void
    {
        $paix = $this->etab('Pharmacie de la Paix');

        $this->envoyer(['fichier' => UploadedFile::fake()->createWithContent('programme.txt', "Pharmacie de la Paix\n")])->assertRedirect();
        $lot = $this->lot();
        $this->assertArrayHasKey('candidats', $lot->lignes[0]);
        $this->assertSame($paix->id, $lot->lignes[0]['etablissement_id']);

        $this->envoyer(['fichier' => UploadedFile::fake()->createWithContent('export.txt', "nom;commune\nPharmacie de la Paix;Cocody\n")])->assertRedirect();
        $csv = $this->lot();
        $this->assertArrayNotHasKey('candidats', $csv->lignes[0], 'un .txt à en-têtes passe par l\'import tableur');
        $this->assertSame('ok', $csv->lignes[0]['statut']);
    }

    public function test_csv_existant_toujours_accepte(): void
    {
        $this->etab('Pharmacie de la Paix');
        $this->envoyer(['fichier' => UploadedFile::fake()->createWithContent('p.csv', "nom;commune\nPharmacie de la Paix;Cocody\n")])->assertRedirect();

        $this->assertSame(1, $this->lot()->nb_valides);
        $this->get('/admin/gardes/lots/'.$this->lot()->id)->assertOk()->assertSee('Gardes prêtes');
    }

    public function test_aucune_source_est_refusee(): void
    {
        $this->envoyer()->assertSessionHasErrors(['fichier', 'texte']);
        $this->envoyer(['texte' => "12\n--\n"])->assertSessionHasErrors('fichier');
    }

    public function test_pdf_avec_couche_texte(): void
    {
        $paix = $this->etab('Pharmacie de la Paix');
        $this->envoyer(['fichier' => UploadedFile::fake()->createWithContent('garde.pdf', $this->pdf(['Programme de garde - semaine 46', 'Pharmacie de la Paix - Cocody']))])
            ->assertRedirect();

        $ok = collect($this->lot()->lignes)->firstWhere('statut', 'ok');
        $this->assertNotNull($ok);
        $this->assertSame($paix->id, $ok['etablissement_id']);
    }

    public function test_pdf_sans_texte_est_explique(): void
    {
        $this->envoyer(['fichier' => UploadedFile::fake()->createWithContent('scan.pdf', $this->pdf([]))])
            ->assertSessionHasErrors('fichier');
        $this->assertStringContainsString('ne contient pas de texte', session('errors')->first('fichier'));
    }

    public function test_pages_creation_et_lot_flou(): void
    {
        $this->etab('Pharmacie de la Paix');
        $this->get('/admin/gardes/import')->assertOk()->assertSee('collez le texte');
        $this->envoyer(['texte' => "Pharmacie de la Paix\nXyz"]);
        $this->get('/admin/gardes/lots/'.$this->lot()->id)->assertOk()
            ->assertSee('Enregistrer les correspondances')->assertSee('pharmacies-ville', false);
    }

    public function test_ocr_image(): void
    {
        $exe = ExtracteurTexte::cheminTesseract();
        $police = 'C:\\Windows\\Fonts\\arial.ttf';
        if (! $exe || ! extension_loaded('gd') || ! is_file($police)) {
            $this->markTestSkipped('Tesseract, GD ou police absents.');
        }
        $this->etab('Pharmacie de la Paix');

        $im = imagecreatetruecolor(1000, 120);
        imagefilledrectangle($im, 0, 0, 1000, 120, imagecolorallocate($im, 255, 255, 255));
        imagettftext($im, 30, 0, 20, 70, imagecolorallocate($im, 0, 0, 0), $police, 'Pharmacie de la Paix - Cocody');
        $chemin = tempnam(sys_get_temp_dir(), 'ocr').'.png';
        imagepng($im, $chemin);

        $this->envoyer(['fichier' => new UploadedFile($chemin, 'photo.png', 'image/png', null, true)])->assertRedirect();
        @unlink($chemin);

        $this->assertSame(1, $this->lot()->nb_valides);
    }

    /** PDF minimal avec couche texte (Helvetica), une ligne par entrée. */
    private function pdf(array $lignes): string
    {
        $flux = "BT /F1 12 Tf 14 TL 50 780 Td\n";
        foreach ($lignes as $l) {
            $flux .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l).") Tj T*\n";
        }
        $flux .= 'ET';

        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($flux)." >>\nstream\n{$flux}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        return $pdf."trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
