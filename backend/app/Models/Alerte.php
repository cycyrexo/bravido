<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Alerte extends Model
{
    public const MOTIFS = [
        'malaise' => 'Malaise',
        'accident' => 'Accident',
        'agression' => 'Agression',
        'autre' => 'Autre urgence',
    ];

    protected $fillable = [
        'motif', 'lat', 'lng', 'precision_m', 'statut',
        'lien_token', 'started_at', 'expires_at', 'closed_at',
    ];

    protected $hidden = ['lien_token'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function destinataires(): HasMany
    {
        return $this->hasMany(AlerteDestinataire::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(AlertePosition::class);
    }

    public function dernierePosition(): HasOne
    {
        return $this->hasOne(AlertePosition::class)->latestOfMany('recorded_at');
    }

    public function estActive(): bool
    {
        return $this->statut === 'active' && $this->expires_at->isFuture();
    }

    public function lienCarte(): string
    {
        return route('alerte.carte', $this->lien_token);
    }

    public function libelleMotif(): string
    {
        return self::MOTIFS[$this->motif] ?? $this->motif;
    }
}
