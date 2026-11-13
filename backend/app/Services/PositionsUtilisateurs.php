<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\PropositionPosition;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Positions confirmées par les utilisateurs sur place (F2).
 * Un établissement est signalé « position à valider » lorsque au moins 3 utilisateurs distincts
 * proposent des points situés à moins de 60 m de la médiane des propositions. Jamais publié automatiquement :
 * un modérateur valide ou rejette au back-office.
 */
class PositionsUtilisateurs
{
    public const PRECISION_MAX_M = 50;

    public const RAYON_GROUPE_M = 60.0;

    public const SEUIL_UTILISATEURS = 3;

    public const RETENTION_JOURS = 90;

    /** Enregistre la proposition (1 par utilisateur et par établissement) puis réévalue le seuil. */
    public function enregistrer(Etablissement $etab, User $user, float $lat, float $lng, int $precisionM): PropositionPosition
    {
        $p = new PropositionPosition(['lat' => round($lat, 7), 'lng' => round($lng, 7), 'precision_m' => $precisionM]);
        $p->etablissement_id = $etab->id;
        $p->user_id = $user->id;
        $p->save();

        $this->evaluer($etab);

        return $p;
    }

    public function dejaPropose(Etablissement $etab, User $user): bool
    {
        return PropositionPosition::where('etablissement_id', $etab->id)->where('user_id', $user->id)->exists();
    }

    /**
     * Point consensuel : médiane des propositions, valeurs aberrantes (> 60 m) écartées, puis médiane des retenues.
     *
     * @return array{lat: float, lng: float, nb: int, total: int}|null null tant que le seuil de 3 utilisateurs distincts n'est pas atteint
     */
    public function consensus(Etablissement|int $etab): ?array
    {
        $id = $etab instanceof Etablissement ? $etab->id : $etab;

        return $this->consensusDe(PropositionPosition::where('etablissement_id', $id)->get(['user_id', 'lat', 'lng']));
    }

    /** @param  Collection<int, PropositionPosition>  $points */
    public function consensusDe(Collection $points): ?array
    {
        if ($points->count() < self::SEUIL_UTILISATEURS) {
            return null;
        }
        $med = ['lat' => self::mediane($points->pluck('lat')->all()), 'lng' => self::mediane($points->pluck('lng')->all())];
        $retenus = $points->filter(
            fn ($p) => Geolocalisation::distanceM($p->lat, $p->lng, $med['lat'], $med['lng']) <= self::RAYON_GROUPE_M
        );
        $distincts = $retenus->pluck('user_id')->unique()->count();
        if ($distincts < self::SEUIL_UTILISATEURS) {
            return null;
        }

        return [
            'lat' => round(self::mediane($retenus->pluck('lat')->all()), 7),
            'lng' => round(self::mediane($retenus->pluck('lng')->all()), 7),
            'nb' => $distincts,
            'total' => $points->count(),
        ];
    }

    /** Met à jour le drapeau « position à valider » de l'établissement. */
    public function evaluer(Etablissement $etab): ?array
    {
        $c = $this->consensus($etab);
        if ($c && $etab->position_a_valider_at === null) {
            $etab->forceFill(['position_a_valider_at' => now()])->saveQuietly();
        } elseif (! $c && $etab->position_a_valider_at !== null) {
            $etab->forceFill(['position_a_valider_at' => null])->saveQuietly();
        }

        return $c;
    }

    /** Supprime toutes les propositions d'un établissement (après validation, rejet ou position fixée autrement). */
    public function purgerPour(Etablissement $etab): int
    {
        $n = PropositionPosition::where('etablissement_id', $etab->id)->delete();
        if ($etab->position_a_valider_at !== null) {
            $etab->forceFill(['position_a_valider_at' => null])->saveQuietly();
        }

        return $n;
    }

    /** Purge des propositions de plus de 90 jours, puis réévaluation des établissements encore signalés. */
    public function purgerAnciennes(): int
    {
        $n = PropositionPosition::where('created_at', '<', now()->subDays(self::RETENTION_JOURS))->delete();
        Etablissement::whereNotNull('position_a_valider_at')->each(fn (Etablissement $e) => $this->evaluer($e));

        return $n;
    }

    public static function mediane(array $valeurs): float
    {
        sort($valeurs);
        $n = count($valeurs);
        $m = intdiv($n, 2);

        return $n % 2 ? (float) $valeurs[$m] : ((float) $valeurs[$m - 1] + (float) $valeurs[$m]) / 2;
    }
}
