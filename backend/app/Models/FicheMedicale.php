<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fiche médicale d'urgence. Les champs sensibles sont chiffrés au repos (clé APP_KEY).
 * Elle n'est jamais envoyée par SMS ni affichée dans le back-office.
 */
class FicheMedicale extends Model
{
    protected $table = 'fiches_medicales';

    protected $fillable = [
        'allergies', 'traitements', 'pathologies',
        'personne_a_prevenir_nom', 'personne_a_prevenir_tel', 'notes', 'partage',
    ];

    protected function casts(): array
    {
        return [
            'allergies' => 'encrypted',
            'traitements' => 'encrypted',
            'pathologies' => 'encrypted',
            'personne_a_prevenir_nom' => 'encrypted',
            'personne_a_prevenir_tel' => 'encrypted',
            'notes' => 'encrypted',
            'partage' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Représentation API (sans l'id, avec le groupe sanguin porté par l'utilisateur). */
    public function versApi(): array
    {
        return [
            'groupe_sanguin' => $this->user?->groupe_sanguin,
            'allergies' => $this->allergies,
            'traitements' => $this->traitements,
            'pathologies' => $this->pathologies,
            'personne_a_prevenir_nom' => $this->personne_a_prevenir_nom,
            'personne_a_prevenir_tel' => $this->personne_a_prevenir_tel,
            'notes' => $this->notes,
            'partage' => (bool) $this->partage,
        ];
    }
}
