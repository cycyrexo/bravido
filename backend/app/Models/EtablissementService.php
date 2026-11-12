<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EtablissementService extends Model
{
    protected $table = 'etab_services';

    public $timestamps = false;

    protected $fillable = ['etablissement_id', 'service'];
}
