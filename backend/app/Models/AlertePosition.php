<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertePosition extends Model
{
    public $timestamps = false;

    protected $fillable = ['alerte_id', 'lat', 'lng', 'precision_m', 'recorded_at'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'recorded_at' => 'datetime'];
    }

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(Alerte::class);
    }
}
