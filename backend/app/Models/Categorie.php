<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categorie extends Model
{
    public const PHARMACIE = 'pharmacie';

    protected $fillable = ['code', 'libelle', 'module', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public static function idPour(string $code): ?int
    {
        return static::where('code', $code)->value('id');
    }
}
