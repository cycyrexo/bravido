<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\GeocodeCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Assistant de géolocalisation : propose jusqu'à 3 positions candidates via OpenStreetMap Nominatim.
 * Politique d'usage respectée : 1 requête/s maximum, User-Agent identifié, cache en base (90 jours).
 * Un candidat n'est jamais appliqué automatiquement : l'admin valide.
 */
class Geolocalisation
{
    public const RAYON_VILLE_KM = 25.0;

    public const TTL_JOURS = 90;

    private const URL = 'https://nominatim.openstreetmap.org/search';

    /** Horodatage (microtime) de la dernière requête réseau, pour espacer à 1/s. */
    private static float $derniereRequete = 0.0;

    /**
     * @return array{centre: array{lat: float, lng: float}|null, candidats: list<array<string, mixed>>}
     */
    public function candidats(Etablissement $etab): array
    {
        $etab->loadMissing('commune.ville');
        $centre = $this->centreVille($etab);
        $ville = $etab->commune->ville->nom ?? '';
        $commune = $etab->commune->nom ?? '';

        $requetes = [];
        if (! str_starts_with($etab->nom, '(nom ')) {
            $requetes[] = "{$etab->nom}, {$commune}, {$ville}, Côte d'Ivoire";
            // Variante épurée (« ABOBO PK 18 - NDOTRE PHARMACIE N’DOTRE » → « NDOTRE PHARMACIE N'DOTRE »)
            $simple = self::nomSimplifie($etab->nom);
            if ($simple !== '' && $simple !== $etab->nom) {
                $requetes[] = "{$simple}, {$commune}, {$ville}, Côte d'Ivoire";
            }
        }
        $lieu = trim((string) ($etab->adresse ?: $etab->repere));
        if ($lieu !== '') {
            $requetes[] = "{$lieu}, {$commune}, {$ville}, Côte d'Ivoire";
        }

        $candidats = [];
        foreach ($requetes as $q) {
            foreach ($this->rechercher($q, $centre) as $r) {
                $c = $this->versCandidat($r, $q, $centre);
                if ($c === null || $this->dejaPresent($candidats, $c)) {
                    continue;
                }
                $candidats[] = $c;
            }
            if (count($candidats) >= 3) {
                break;
            }
        }

        return ['centre' => $centre, 'candidats' => array_slice($candidats, 0, 3)];
    }

    /** Retire le préfixe « lieu - », la ponctuation typographique et les parenthèses. */
    public static function nomSimplifie(string $nom): string
    {
        $nom = str_replace(['’', '‘', '`', '´'], "'", $nom);
        $nom = preg_replace('/\([^)]*\)/u', ' ', $nom) ?? $nom;
        if (preg_match('/^.{2,40}?\s+[-–—]\s+(.{4,})$/u', $nom, $m)) {
            $nom = $m[1];
        }

        return trim(preg_replace('/\s+/u', ' ', $nom) ?? $nom);
    }

    /** @return array{lat: float, lng: float}|null */
    public function centreVille(Etablissement $etab): ?array
    {
        $ville = $etab->commune?->ville;
        if ($ville && $ville->lat !== null && $ville->lng !== null) {
            return ['lat' => (float) $ville->lat, 'lng' => (float) $ville->lng];
        }
        $commune = $etab->commune;
        if ($commune && $commune->lat !== null && $commune->lng !== null) {
            return ['lat' => (float) $commune->lat, 'lng' => (float) $commune->lng];
        }

        return null;
    }

    public function dansRayonVille(float $lat, float $lng, ?array $centre): bool
    {
        if ($centre === null) {
            return true;
        }

        return self::distanceM($lat, $lng, $centre['lat'], $centre['lng']) <= self::RAYON_VILLE_KM * 1000;
    }

    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($a)));
    }

    /** @return list<array{lat: float, lng: float, nom: string}> */
    private function rechercher(string $requete, ?array $centre): array
    {
        $cle = sha1(Str::lower(Str::squish($requete)));

        $cache = GeocodeCache::where('cle', $cle)->where('expires_at', '>', now())->first();
        if ($cache) {
            return $cache->reponse;
        }

        $this->patienter();

        $params = ['q' => $requete, 'format' => 'jsonv2', 'limit' => 3, 'countrycodes' => 'ci', 'accept-language' => 'fr'];
        if ($centre) {
            // Fenêtre englobant le rayon de la ville (gauche, haut, droite, bas) ; le filtre strict est fait ensuite
            $dLat = self::RAYON_VILLE_KM / 111.32;
            $dLng = self::RAYON_VILLE_KM / (111.32 * max(cos(deg2rad($centre['lat'])), 0.01));
            $params['viewbox'] = implode(',', [$centre['lng'] - $dLng, $centre['lat'] + $dLat, $centre['lng'] + $dLng, $centre['lat'] - $dLat]);
        }

        try {
            // Bundle CA du projet (WAMP n'a pas de curl.cainfo par défaut), comme pour FCM
            $ca = config('services.fcm.ca_bundle');
            $reponse = Http::withUserAgent('BRAVIDO-backoffice/1.0 (admin@bravido.ci)')
                ->withOptions($ca ? ['verify' => $ca] : [])
                ->timeout(10)->acceptJson()->get(self::URL, $params);
        } catch (\Throwable) {
            self::$derniereRequete = microtime(true);

            return []; // réseau indisponible : rien en cache, l'admin place le point à la main
        }

        self::$derniereRequete = microtime(true);

        if (! $reponse->successful()) {
            return [];
        }

        $resultats = [];
        foreach ((array) $reponse->json() as $r) {
            if (isset($r['lat'], $r['lon'])) {
                $resultats[] = [
                    'lat' => (float) $r['lat'], 'lng' => (float) $r['lon'],
                    'nom' => Str::limit((string) ($r['display_name'] ?? ''), 160, '…'),
                ];
            }
        }

        GeocodeCache::updateOrCreate(['cle' => $cle], [
            'requete' => Str::limit($requete, 300, ''),
            'reponse' => $resultats,
            'expires_at' => now()->addDays(self::TTL_JOURS),
        ]);

        return $resultats;
    }

    /** Espace les requêtes réseau d'au moins 1 s (politique Nominatim). */
    private function patienter(): void
    {
        $delai = (float) config('services.nominatim.delai', 1.1);
        $attente = self::$derniereRequete + $delai - microtime(true);
        if ($attente > 0) {
            usleep((int) ($attente * 1_000_000));
        }
    }

    private function versCandidat(array $r, string $requete, ?array $centre): ?array
    {
        if (! $this->dansRayonVille($r['lat'], $r['lng'], $centre)) {
            return null;
        }

        return [
            'lat' => round($r['lat'], 7), 'lng' => round($r['lng'], 7),
            'libelle' => $r['nom'], 'requete' => $requete,
            'distance_centre_m' => $centre ? (int) round(self::distanceM($r['lat'], $r['lng'], $centre['lat'], $centre['lng'])) : null,
        ];
    }

    private function dejaPresent(array $liste, array $c): bool
    {
        foreach ($liste as $x) {
            if (self::distanceM($x['lat'], $x['lng'], $c['lat'], $c['lng']) < 30) {
                return true;
            }
        }

        return false;
    }
}
