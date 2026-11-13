<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\EtabPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Photos d'une fiche Premium : 5 maximum, redimensionnées et miniaturisées avec GD, stockées sur le disque public. */
class PhotosEtablissement
{
    public const MAX = 5;

    private const GRAND = 1280;

    private const PETIT = 400;

    public function ajouter(Etablissement $etab, UploadedFile $fichier): EtabPhoto
    {
        if ($etab->photos()->count() >= self::MAX) {
            throw new \DomainException('Maximum '.self::MAX.' photos par fiche.');
        }

        $src = @imagecreatefromstring((string) file_get_contents($fichier->getRealPath()));
        if ($src === false) {
            throw new \DomainException("Image illisible. Utilisez un fichier JPEG, PNG ou WebP.");
        }
        $src = $this->orienter($src, $fichier->getRealPath());

        $base = 'etablissements/'.$etab->id.'/'.Str::uuid()->toString();
        $grand = $this->redimensionner($src, self::GRAND);
        $petit = $this->redimensionner($src, self::PETIT);

        $disque = Storage::disk('public');
        $disque->put($base.'.jpg', $this->jpeg($grand, 82));
        $disque->put($base.'_t.jpg', $this->jpeg($petit, 78));

        return $etab->photos()->create([
            'chemin' => $base.'.jpg',
            'miniature' => $base.'_t.jpg',
            'ordre' => (int) $etab->photos()->max('ordre') + 1,
        ]);
    }

    public function supprimer(EtabPhoto $photo): void
    {
        Storage::disk('public')->delete([$photo->chemin, $photo->miniature]);
        $photo->delete();
    }

    public function supprimerToutes(Etablissement $etab): void
    {
        $etab->photos->each(fn (EtabPhoto $p) => $this->supprimer($p));
    }

    private function orienter(\GdImage $img, string $chemin): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($chemin);
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };

        return $angle ? (imagerotate($img, $angle, 0) ?: $img) : $img;
    }

    private function redimensionner(\GdImage $img, int $max): \GdImage
    {
        $l = imagesx($img);
        $h = imagesy($img);
        $ratio = min(1, $max / max($l, $h));
        $nl = max(1, (int) round($l * $ratio));
        $nh = max(1, (int) round($h * $ratio));

        $dest = imagecreatetruecolor($nl, $nh);
        imagefill($dest, 0, 0, imagecolorallocate($dest, 255, 255, 255)); // fond blanc pour les PNG transparents
        imagecopyresampled($dest, $img, 0, 0, 0, 0, $nl, $nh, $l, $h);

        return $dest;
    }

    private function jpeg(\GdImage $img, int $qualite): string
    {
        ob_start();
        imagejpeg($img, null, $qualite);

        return (string) ob_get_clean();
    }
}
