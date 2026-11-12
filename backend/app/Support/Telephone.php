<?php

namespace App\Support;

final class Telephone
{
    /**
     * Normalise un numéro au format E.164.
     * Un numéro de 10 chiffres sans indicatif est considéré comme ivoirien (+225).
     */
    public static function normaliser(?string $brut): ?string
    {
        if ($brut === null || trim($brut) === '') {
            return null;
        }

        $t = preg_replace('/[^\d+]/', '', $brut);

        if (str_starts_with($t, '00')) {
            $t = '+'.substr($t, 2);
        }

        if (! str_starts_with($t, '+')) {
            if (strlen($t) === 10) {
                $t = '+225'.$t;
            } elseif (strlen($t) === 13 && str_starts_with($t, '225')) {
                $t = '+'.$t;
            } else {
                return null;
            }
        }

        return preg_match('/^\+\d{8,15}$/', $t) ? $t : null;
    }
}
