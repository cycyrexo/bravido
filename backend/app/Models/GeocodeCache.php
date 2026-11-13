<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeocodeCache extends Model
{
    protected $table = 'geocode_cache';

    public const UPDATED_AT = null;

    protected $fillable = ['cle', 'requete', 'reponse', 'expires_at'];

    protected function casts(): array
    {
        return ['reponse' => 'array', 'expires_at' => 'datetime'];
    }
}
