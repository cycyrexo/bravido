<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    public const ROLES = ['user', 'resp_etab', 'moderateur', 'admin'];

    public const GROUPES_SANGUINS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    // role, statut, email et password ne sont jamais assignés en masse
    protected $fillable = ['telephone', 'nom', 'ville_id', 'groupe_sanguin'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'telephone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function complices(): HasMany
    {
        return $this->hasMany(Complice::class)->orderBy('ordre');
    }

    public function alertes(): HasMany
    {
        return $this->hasMany(Alerte::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function favoris(): BelongsToMany
    {
        return $this->belongsToMany(Etablissement::class, 'favoris');
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['moderateur', 'admin'], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function nomAffiche(): string
    {
        return $this->nom ?: $this->telephone;
    }
}
