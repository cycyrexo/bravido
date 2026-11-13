<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** F13 : avis d'un utilisateur sur un établissement (visible publiquement seulement une fois approuvé). */
class Avis extends Model
{
    protected $table = 'avis';

    protected $fillable = ['note', 'commentaire'];

    protected function casts(): array
    {
        return [
            'note' => 'integer',
            'drapeaux' => 'array',
            'reponse_drapeaux' => 'array',
            'modere_at' => 'datetime',
            'reponse_at' => 'datetime',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function signalements(): HasMany
    {
        return $this->hasMany(AvisSignalement::class);
    }

    public function scopeApprouves(Builder $q): Builder
    {
        return $q->where('avis.statut', 'approuve');
    }

    /** Nom public : « Prénom I. » ou « Utilisateur BRAVIDO » (jamais le téléphone). */
    public function nomPublic(): string
    {
        $mots = preg_split('/\s+/', trim((string) $this->auteur?->nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($mots === []) {
            return 'Utilisateur BRAVIDO';
        }
        $prenom = mb_convert_case($mots[0], MB_CASE_TITLE);

        return isset($mots[1]) ? $prenom.' '.mb_strtoupper(mb_substr($mots[1], 0, 1)).'.' : $prenom;
    }

    /** La réponse du responsable n'est publique qu'une fois approuvée. */
    public function reponsePublique(): ?array
    {
        if ($this->reponse_statut !== 'approuvee' || ! $this->reponse) {
            return null;
        }

        return ['texte' => $this->reponse, 'date' => $this->reponse_at?->toDateString()];
    }
}
