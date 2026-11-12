<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fiche extends Model
{
    protected $fillable = [
        'type', 'etablissement_id', 'soumis_par', 'donnees', 'pieces',
        'statut', 'traite_par', 'traite_at', 'motif_rejet',
    ];

    protected function casts(): array
    {
        return [
            'donnees' => 'array',
            'pieces' => 'array',
            'traite_at' => 'datetime',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soumis_par');
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }
}
