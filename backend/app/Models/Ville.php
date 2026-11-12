<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ville extends Model
{
    protected $fillable = ['nom', 'region', 'lat', 'lng', 'actif'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'actif' => 'boolean'];
    }

    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class)->orderBy('nom');
    }
}
