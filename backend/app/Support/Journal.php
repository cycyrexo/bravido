<?php

namespace App\Support;

use App\Models\JournalAdmin;
use Illuminate\Database\Eloquent\Model;

/** Traçabilité des actions du back-office (table journal_admin). */
final class Journal
{
    public static function log(string $action, ?Model $cible = null, ?array $avant = null, ?array $apres = null): void
    {
        JournalAdmin::create([
            'admin_id' => auth()->id(),
            'action' => $action,
            'cible_type' => $cible ? class_basename($cible) : null,
            'cible_id' => $cible?->getKey(),
            'avant' => $avant,
            'apres' => $apres,
            'ip' => request()->ip(),
        ]);
    }
}
