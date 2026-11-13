<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EtabStat extends Model
{
    protected $table = 'etab_stats';

    public $timestamps = false;

    protected $fillable = ['etablissement_id', 'jour', 'vues', 'appels', 'itineraires'];

    protected function casts(): array
    {
        return ['jour' => 'date'];
    }
}
