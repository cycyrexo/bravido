<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvisSignalement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['avis_id', 'user_id', 'motif'];
}
