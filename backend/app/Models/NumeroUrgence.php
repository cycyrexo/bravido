<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumeroUrgence extends Model
{
    protected $table = 'numeros_urgence';

    protected $fillable = ['libelle', 'numero', 'description', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }
}
