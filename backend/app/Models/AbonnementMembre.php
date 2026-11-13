<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbonnementMembre extends Model
{
    protected $table = 'abonnement_membres';

    public function abonnement(): BelongsTo
    {
        return $this->belongsTo(Abonnement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
