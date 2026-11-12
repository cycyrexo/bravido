<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageSupport extends Model
{
    protected $table = 'messages_support';

    public const TYPES = ['observation', 'suggestion', 'preoccupation', 'signalement'];

    protected $fillable = ['type', 'etablissement_id', 'texte'];

    protected function casts(): array
    {
        return ['repondu_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function reponduPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repondu_par');
    }
}
