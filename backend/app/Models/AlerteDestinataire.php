<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlerteDestinataire extends Model
{
    protected $fillable = [
        'complice_id', 'nom', 'telephone', 'destinataire_user_id',
        'push_envoye_at', 'sms_envoye_at', 'recu_at', 'vu_at', 'reponse', 'repondu_at',
    ];

    protected function casts(): array
    {
        return [
            'push_envoye_at' => 'datetime',
            'sms_envoye_at' => 'datetime',
            'recu_at' => 'datetime',
            'vu_at' => 'datetime',
            'repondu_at' => 'datetime',
        ];
    }

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(Alerte::class);
    }

    public function destinataireUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_user_id');
    }

    /** URG-04 : état lisible du suivi. */
    public function etat(): string
    {
        return match (true) {
            $this->reponse !== null => $this->reponse,
            $this->vu_at !== null => 'vue',
            $this->recu_at !== null => 'recue',
            $this->push_envoye_at !== null || $this->sms_envoye_at !== null => 'envoyee',
            default => 'en_attente',
        };
    }
}
