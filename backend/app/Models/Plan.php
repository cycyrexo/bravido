<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['code', 'nom', 'prix_xof', 'duree_jours', 'profils_max', 'actif', 'a_confirmer'];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'a_confirmer' => 'boolean', 'prix_xof' => 'integer', 'duree_jours' => 'integer', 'profils_max' => 'integer'];
    }
}
