<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** F19 : abonnement famille. N'influence JAMAIS l'envoi des alertes (règle produit). */
class Abonnement extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return ['debut' => 'datetime', 'fin' => 'datetime'];
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function membres(): HasMany
    {
        return $this->hasMany(AbonnementMembre::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function scopeEnCours(Builder $q): Builder
    {
        return $q->where('statut', 'actif')->where('fin', '>', now());
    }

    public function estActif(): bool
    {
        return $this->statut === 'actif' && $this->fin->isFuture();
    }

    /** Nombre de places occupées (propriétaire + membres invités ou acceptés). */
    public function profilsOccupes(): int
    {
        return 1 + $this->membres()->count();
    }
}
