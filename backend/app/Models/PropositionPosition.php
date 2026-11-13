<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Position GPS proposée par un utilisateur présent sur place (jamais exposée avec l'identité de l'utilisateur). */
class PropositionPosition extends Model
{
    protected $table = 'propositions_position';

    public $timestamps = false;

    protected $fillable = ['lat', 'lng', 'precision_m'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'precision_m' => 'integer', 'created_at' => 'datetime'];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
