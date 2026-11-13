<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Garde déclarée par le responsable d'une pharmacie ; publiée seulement après validation au back-office. */
class GardeDeclaration extends Model
{
    protected $table = 'garde_declarations';

    protected $fillable = ['date_debut', 'date_fin'];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date', 'traite_at' => 'datetime'];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function garde(): BelongsTo
    {
        return $this->belongsTo(Garde::class);
    }
}
