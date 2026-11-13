<?php

namespace App\Http\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Page publique de téléchargement de l'APK de test (Android).
 * Les deux fichiers sont déposés à la main sur le serveur (scp), hors dépôt git :
 *   storage/app/telechargement/bravido-test.apk
 *   storage/app/telechargement/bravido-test.apk.json
 */
class TelechargementController extends Controller
{
    private const DOSSIER = 'telechargement';
    private const APK = 'bravido-test.apk';

    private function chemin(string $fichier): string
    {
        return storage_path('app/'.self::DOSSIER.'/'.$fichier);
    }

    public function page(Request $request)
    {
        $apk = $this->chemin(self::APK);
        $disponible = is_file($apk);
        $meta = [];

        if ($disponible) {
            $json = $this->chemin(self::APK.'.json');
            if (is_file($json)) {
                $lu = json_decode((string) file_get_contents($json), true);
                $meta = is_array($lu) ? $lu : [];
            }
            $meta['size_bytes'] = $meta['size_bytes'] ?? filesize($apk);
            if (! empty($meta['build_date'])) {
                try {
                    $meta['build_date_fr'] = Carbon::parse($meta['build_date'])->format('d/m/Y H:i');
                } catch (\Throwable) {
                    $meta['build_date_fr'] = (string) $meta['build_date'];
                }
            }
            $meta['taille'] = number_format(((int) $meta['size_bytes']) / 1048576, 1, ',', ' ').' Mo';
        }

        $urlPage = route('telechargement.page');

        return response()->view('public.telecharger', [
            'disponible' => $disponible,
            'meta' => $meta,
            'urlApk' => route('telechargement.apk'),
            'qr' => $this->qrSvg($urlPage),
        ]);
    }

    public function apk(): BinaryFileResponse
    {
        $apk = $this->chemin(self::APK);
        abort_unless(is_file($apk), 404, "L'APK n'est pas encore disponible.");

        return response()->download($apk, 'bravido-test.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-cache, must-revalidate',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** QR code en SVG, généré côté serveur (aucune ressource externe). */
    private function qrSvg(string $contenu): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd())))->writeString($contenu);

        // Retire la déclaration XML pour l'inclure directement dans le HTML.
        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }
}
