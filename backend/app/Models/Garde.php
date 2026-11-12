<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Garde extends Model
{
    protected $fillable = ['etablissement_id', 'date_debut', 'date_fin', 'source', 'lot_import_id'];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date'];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ImportLot::class, 'lot_import_id');
    }

    public function scopeEnCours(Builder $query): Builder
    {
        $jour = today()->toDateString();

        return $query->where('date_debut', '<=', $jour)->where('date_fin', '>=', $jour);
    }

    /** Gardes qui chevauchent la période [debut, fin]. */
    public function scopeChevauche(Builder $query, string $debut, string $fin): Builder
    {
        return $query->where('date_debut', '<=', $fin)->where('date_fin', '>=', $debut);
    }
}
