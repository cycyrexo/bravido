<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assurance extends Model
{
    protected $fillable = ['nom', 'type', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }
}
