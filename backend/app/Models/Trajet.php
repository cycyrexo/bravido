<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** « Je rentre chez moi » : trajet surveillé avec échéance. */
class Trajet extends Model
{
    protected $fillable = ['partage_position', 'complice_ids'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'echeance_at' => 'datetime',
            'rappel_envoye_at' => 'datetime',
            'arrive_at' => 'datetime',
            'position_at' => 'datetime',
            'partage_position' => 'boolean',
            'complice_ids' => 'array',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(Alerte::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(TrajetPosition::class);
    }

    public function estEnCours(): bool
    {
        return $this->statut === 'en_cours';
    }
}
