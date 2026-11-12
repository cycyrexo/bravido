<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complice extends Model
{
    protected $fillable = ['nom', 'telephone', 'ordre'];

    protected $hidden = ['invitation_token'];

    protected function casts(): array
    {
        return ['repondu_at' => 'datetime'];
    }

    /** Propriétaire du cercle (la personne protégée). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Compte BRAVIDO du complice, s'il en a un. */
    public function compliceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complice_user_id');
    }
}
