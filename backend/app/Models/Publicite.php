<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Publicite extends Model
{
    protected $fillable = ['annonceur', 'titre', 'image', 'lien', 'date_debut', 'date_fin', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date', 'actif' => 'boolean'];
    }

    public function villes(): BelongsToMany
    {
        return $this->belongsToMany(Ville::class, 'pub_villes');
    }

    /** Annonces diffusables aujourd'hui, nationales ou ciblées sur la ville. */
    public function scopeDiffusables(Builder $query, ?int $villeId): Builder
    {
        $jour = today()->toDateString();

        return $query->where('actif', true)
            ->where('date_debut', '<=', $jour)
            ->where('date_fin', '>=', $jour)
            ->where(function (Builder $q) use ($villeId) {
                $q->whereDoesntHave('villes');
                if ($villeId) {
                    $q->orWhereHas('villes', fn (Builder $v) => $v->where('villes.id', $villeId));
                }
            });
    }

    public function imageUrl(): string
    {
        return Storage::disk('public')->url($this->image);
    }
}
