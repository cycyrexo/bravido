<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportLot extends Model
{
    protected $fillable = [
        'ville_id', 'date_debut', 'date_fin', 'fichier_nom', 'statut',
        'lignes', 'nb_valides', 'nb_erreurs', 'created_by', 'publie_at',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'lignes' => 'array',
            'publie_at' => 'datetime',
        ];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function gardes(): HasMany
    {
        return $this->hasMany(Garde::class, 'lot_import_id');
    }
}
