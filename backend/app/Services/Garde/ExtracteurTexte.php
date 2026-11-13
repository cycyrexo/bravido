<?php

namespace App\Services\Garde;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Extrait le texte d'un programme de garde : PDF (couche texte), texte brut, image (OCR Tesseract
 * uniquement s'il est installé : détecté à l'exécution, jamais téléchargé).
 */
class ExtracteurTexte
{
    public const EXT_IMAGES = ['png', 'jpg', 'jpeg', 'bmp', 'tif', 'tiff', 'gif', 'webp'];

    public const MESSAGE_OCR_ABSENT = 'OCR non installé : collez le texte ou utilisez un PDF texte.';

    /** Chemin de tesseract(.exe) ou null. */
    public static function cheminTesseract(): ?string
    {
        $candidats = array_filter([
            config('services.tesseract.path'),
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            '/usr/bin/tesseract',
            '/usr/local/bin/tesseract',
            '/opt/homebrew/bin/tesseract',
        ]);
        foreach ($candidats as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        // Dans le PATH
        try {
            $p = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', 'tesseract'] : ['which', 'tesseract']);
            $p->setTimeout(5)->run();
            $premier = trim(strtok($p->getOutput(), "\r\n") ?: '');
            if ($p->isSuccessful() && $premier !== '' && is_file($premier)) {
                return $premier;
            }
        } catch (Throwable) {
        }

        return null;
    }

    public static function ocrDisponible(): bool
    {
        return self::cheminTesseract() !== null;
    }

    /** Langues OCR : français si le fichier de langue est installé, sinon anglais (l'alphabet latin suffit, les accents sont ignorés au rapprochement). */
    private static function langues(string $exe): string
    {
        $dossiers = array_filter([
            getenv('TESSDATA_PREFIX') ?: null,
            dirname($exe).DIRECTORY_SEPARATOR.'tessdata',
            '/usr/share/tesseract-ocr/5/tessdata',
            '/usr/share/tesseract-ocr/4.00/tessdata',
            '/usr/share/tessdata',
        ]);
        foreach ($dossiers as $d) {
            if (is_file($d.DIRECTORY_SEPARATOR.'fra.traineddata')) {
                return is_file($d.DIRECTORY_SEPARATOR.'eng.traineddata') ? 'fra+eng' : 'fra';
            }
        }

        return 'eng';
    }

    /** @throws ValidationException si le fichier est inexploitable */
    public function depuisFichier(UploadedFile $fichier): string
    {
        $ext = strtolower($fichier->getClientOriginalExtension());

        $texte = match (true) {
            $ext === 'pdf' => $this->pdf($fichier->getRealPath()),
            in_array($ext, self::EXT_IMAGES, true) => $this->image($fichier->getRealPath()),
            default => $this->brut($fichier->getRealPath()),
        };

        return $this->nettoyer($texte);
    }

    public function pdf(string $chemin): string
    {
        try {
            $texte = (new Parser)->parseFile($chemin)->getText();
        } catch (Throwable) {
            throw ValidationException::withMessages(['fichier' => 'PDF illisible ou protégé : collez le texte du programme à la place.']);
        }

        if (mb_strlen(trim(preg_replace('/\s+/u', ' ', $texte))) < 20) {
            throw ValidationException::withMessages(['fichier' => 'Ce PDF ne contient pas de texte (document scanné). '
                .(self::ocrDisponible() ? 'Exportez-le en image (PNG/JPG) pour l\'OCR, ou collez le texte.' : self::MESSAGE_OCR_ABSENT)]);
        }

        return $texte;
    }

    public function image(string $chemin): string
    {
        $exe = self::cheminTesseract();
        if (! $exe) {
            throw ValidationException::withMessages(['fichier' => self::MESSAGE_OCR_ABSENT]);
        }

        $p = new Process([$exe, $chemin, 'stdout', '-l', self::langues($exe), '--psm', '6']);
        $p->setTimeout(90)->run();

        if (! $p->isSuccessful() || trim($p->getOutput()) === '') {
            throw ValidationException::withMessages(['fichier' => 'Aucun texte lisible dans cette image (image trop floue ou OCR en échec) : collez le texte à la place.']);
        }

        return $p->getOutput();
    }

    private function brut(string $chemin): string
    {
        $contenu = (string) file_get_contents($chemin);

        return $this->enUtf8($contenu);
    }

    /** Fichiers Windows-1252 fréquents. */
    public function enUtf8(string $contenu): string
    {
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);

        return mb_check_encoding($contenu, 'UTF-8') ? $contenu : mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
    }

    /** Fins de ligne unifiées, espaces insécables et caractères de contrôle retirés. */
    public function nettoyer(string $texte): string
    {
        $texte = $this->enUtf8($texte);
        $texte = str_replace(["\r\n", "\r", "\u{00A0}", "\u{200B}"], ["\n", "\n", ' ', ''], $texte);

        return trim(preg_replace('/[^\P{C}\n\t]/u', '', $texte) ?? $texte);
    }
}
