<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrajetPosition extends Model
{
    public $timestamps = false;

    protected $fillable = ['lat', 'lng', 'precision_m', 'recorded_at'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'lat' => 'float', 'lng' => 'float'];
    }
}
