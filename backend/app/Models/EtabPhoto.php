<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EtabPhoto extends Model
{
    protected $table = 'etab_photos';

    protected $fillable = ['etablissement_id', 'chemin', 'miniature', 'ordre'];

    public function url(): string
    {
        return asset('storage/'.$this->chemin);
    }

    public function urlMiniature(): string
    {
        return asset('storage/'.$this->miniature);
    }
}
