<?php

namespace App\Services\Garde;

use App\Models\Etablissement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Rapprochement flou « ligne de texte » -> pharmacie d'une ville.
 *
 * Score 0..1 :
 *  - similarité de noms normalisés (sans accents, « Phcie » = pharmacie, mots vides retirés,
 *    nom de la commune de la pharmacie ignoré), tolérante aux fautes de frappe / à l'OCR ;
 *  - un numéro de téléphone commun renforce fortement le score ;
 *  - la mention de la commune départage les homonymes.
 *
 * Statuts : « ok » (sûr et sans ambiguïté), « a_verifier » (plausible, à confirmer par l'administrateur),
 * « erreur » (rien de fiable). Seul « ok » est publiable sans intervention.
 */
class PharmacieMatcher
{
    public const SEUIL_AUTO = 0.85;     // score minimal pour une reconnaissance automatique
    public const ECART_AUTO = 0.10;     // écart minimal avec le 2e candidat
    public const SEUIL_VERIF = 0.60;    // en dessous : aucune présélection
    public const SEUIL_PROPOSITION = 0.35; // en dessous : candidat non proposé

    private const MOTS_VIDES = [
        'pharmacie', 'pharmacies', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'et', 'en', 'a', 'au', 'aux',
        'garde', 'gardes', 'programme', 'tel', 'telephone', 'contact', 'cel', 'cell', 'no', 'n',
    ];

    /** Normalise : ASCII minuscule, abréviations de « pharmacie » développées, ponctuation -> espaces. */
    public static function normaliser(string $texte): string
    {
        $t = Str::of($texte)->ascii()->lower()->value();
        $t = preg_replace('/\b(phcie|phacie|pharm|pharmac|phie|pcie|ph)\b\.?/', 'pharmacie', $t);
        $t = preg_replace(['/st\.?/', '/ste\.?/'], ['saint', 'sainte'], $t);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /** Mots significatifs (sans mots vides). @return array<int, string> */
    public static function mots(string $texte): array
    {
        $mots = array_filter(explode(' ', self::normaliser($texte)), fn ($m) => $m !== '' && ! in_array($m, self::MOTS_VIDES, true));

        return array_values(array_unique($mots));
    }

    /** Numéros (8 derniers chiffres) trouvés dans un texte. @return array<int, string> */
    public static function telephones(string $texte): array
    {
        preg_match_all('/\+?\d[\d\s.\-]{6,}\d/', $texte, $m);

        $numeros = [];
        foreach ($m[0] as $brut) {
            $chiffres = preg_replace('/\D/', '', $brut);
            if (strlen($chiffres) >= 8 && strlen($chiffres) <= 13) {
                $numeros[] = substr($chiffres, -8);
            }
        }

        return array_values(array_unique($numeros));
    }

    /**
     * Prépare les pharmacies d'une ville une fois pour toutes les lignes.
     *
     * @param  Collection<int, Etablissement>  $etablissements  (relation commune chargée)
     * @return array<int, array<string, mixed>>
     */
    public function preparer(Collection $etablissements): array
    {
        return $etablissements->map(function (Etablissement $e) {
            $commune = (string) ($e->commune->nom ?? '');
            $motsCommune = self::mots($commune);
            $mots = self::mots($e->nom);
            $sansCommune = array_values(array_diff($mots, $motsCommune));

            return [
                'id' => $e->id,
                'nom' => $e->nom,
                'commune' => $commune,
                'commune_norm' => self::normaliser($commune),
                'mots' => $sansCommune !== [] ? $sansCommune : $mots,
                'mots_commune' => $motsCommune,
                'tels' => self::telephones(implode(' ; ', array_filter([$e->tel1, $e->tel2, $e->tel_autres]))),
            ];
        })->values()->all();
    }

    /**
     * Classe une ligne.
     *
     * @param  array<int, array<string, mixed>>  $pharmacies  résultat de preparer()
     * @return array{score: float, statut: string, candidats: array<int, array<string, mixed>>}
     */
    public function classer(string $ligne, array $pharmacies): array
    {
        $segments = $this->segments($ligne);
        $motsLigne = self::mots($ligne);
        $telsLigne = self::telephones($ligne);
        $ligneNorm = ' '.self::normaliser($ligne).' ';

        $notes = [];
        foreach ($pharmacies as $p) {
            $score = $this->scoreNom($p, $segments, $motsLigne);

            if ($telsLigne && array_intersect($telsLigne, $p['tels'])) {
                $score = max($score + 0.25, 0.75); // peut dépasser 1 : sert à départager, plafonné à l'affichage
            }
            $cite = $p['commune_norm'] !== '' && str_contains($ligneNorm, ' '.$p['commune_norm'].' ');
            if ($cite) {
                $score += 0.05;
            }

            if ($score >= self::SEUIL_PROPOSITION) {
                $notes[] = ['id' => $p['id'], 'nom' => $p['nom'], 'commune' => $p['commune'], 'score' => round($score, 3), 'taille' => count($p['mots']), 'cite' => $cite];
            }
        }

        // Score décroissant ; à égalité, nom le plus spécifique d'abord
        usort($notes, fn ($a, $b) => [$b['score'], $b['taille']] <=> [$a['score'], $a['taille']]);
        $notes = array_slice($notes, 0, 3);

        $premier = $notes[0]['score'] ?? 0.0;
        $second = $notes[1]['score'] ?? 0.0;
        // La commune citée dans la ligne départage deux homonymes (écart plus faible accepté)
        $departage = isset($notes[0]) && $notes[0]['cite'] && ! ($notes[1]['cite'] ?? false) && ($premier - $second) >= 0.04;
        $notes = array_map(function ($n) {
            unset($n['taille'], $n['cite']);
            $n['score'] = min(1.0, $n['score']);

            return $n;
        }, $notes);

        $statut = match (true) {
            $premier >= self::SEUIL_AUTO && (($premier - $second) >= self::ECART_AUTO || $departage) => 'ok',
            $premier >= self::SEUIL_VERIF => 'a_verifier',
            default => 'erreur',
        };

        return ['score' => min(1.0, (float) $premier), 'statut' => $statut, 'candidats' => $notes];
    }

    /** Découpe la ligne en segments (cellules, parties séparées par « - », « ; », « | »…). @return array<int, array<int, string>> */
    private function segments(string $ligne): array
    {
        $parts = preg_split('/\s[-–—\/|]\s|[;|\t]|\s{3,}|[()]/u', $ligne) ?: [$ligne];

        return array_values(array_filter(array_map(fn ($s) => self::mots($s), $parts), fn ($m) => $m !== []));
    }

    /** @param array<int, array<int, string>> $segments */
    private function scoreNom(array $p, array $segments, array $motsLigne): float
    {
        $nom = $p['mots'];
        if ($nom === [] || $motsLigne === []) {
            return 0.0;
        }

        $best = 0.0;

        // 1. Segment contre nom : mesure F (précision/rappel) — cas « une cellule = un nom »
        foreach ($segments as $seg) {
            [$r, $pr] = $this->recouvrement($nom, $seg);
            if ($r + $pr > 0) {
                $best = max($best, 2 * $r * $pr / ($r + $pr));
            }
        }

        // 2. Ligne entière : le nom est contenu dans la ligne (adresse, téléphone… autour), commune ignorée
        $ligneSansCommune = array_values(array_diff($motsLigne, $p['mots_commune'])) ?: $motsLigne;
        [$r, $pr] = $this->recouvrement($nom, $ligneSansCommune);
        $best = max($best, $r * (0.85 + 0.15 * $pr));

        // 3. Tous les mots de la ligne figurent dans le nom (ligne abrégée) : plausible mais pas certain
        if ($pr >= 0.999 && $r > 0) {
            $best = max($best, 0.62 + 0.2 * $r);
        }

        return min(1.0, $best);
    }

    /**
     * @param  array<int, string>  $nom
     * @param  array<int, string>  $ligne
     * @return array{0: float, 1: float}  [rappel sur le nom, précision sur la ligne]
     */
    private function recouvrement(array $nom, array $ligne): array
    {
        if ($ligne === []) {
            return [0.0, 0.0];
        }

        $libres = $ligne;
        $somme = 0.0;
        foreach ($nom as $m) {
            $meilleur = 0.0;
            $cle = null;
            foreach ($libres as $k => $l) {
                $s = $this->similitudeMots($m, $l);
                if ($s > $meilleur) {
                    $meilleur = $s;
                    $cle = $k;
                }
            }
            if ($cle !== null) {
                unset($libres[$cle]);
                $somme += $meilleur;
            }
        }

        return [$somme / count($nom), $somme / count($ligne)];
    }

    private function similitudeMots(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $max = max(strlen($a), strlen($b));
        if ($max < 4 || ctype_digit($a) || ctype_digit($b)) {
            return 0.0; // petits mots et numéros : exacts uniquement (« 2 » n'est pas « 3 »)
        }
        $ratio = 1 - levenshtein($a, $b) / $max;

        return $ratio >= 0.75 ? $ratio : 0.0;
    }
}
