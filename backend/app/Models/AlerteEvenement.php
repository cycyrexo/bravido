<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F22 : une ligne du journal d'audit d'une alerte (aucune donnée personnelle, voir la migration). */
class AlerteEvenement extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'declenchee' => 'Alerte déclenchée',
        'trajet_auto' => 'Déclenchée automatiquement (trajet)',
        'push_envoye' => 'Notification push envoyée',
        'sms_envoye' => 'SMS de secours envoyé',
        'recue' => 'Reçue sur le téléphone',
        'vue' => 'Ouverte par le complice',
        'reponse' => 'Réponse du complice',
        'audio' => 'Message vocal ajouté',
        'cloturee' => 'Clôturée par la personne',
        'annulee' => 'Annulée (fausse alerte)',
        'expiree' => 'Clôturée automatiquement (durée écoulée)',
    ];

    protected $fillable = ['alerte_id', 'type', 'destinataire_ref', 'canal', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(Alerte::class);
    }

    /**
     * Enregistre un événement. Ne doit JAMAIS faire échouer l'alerte : toute erreur est ignorée.
     */
    public static function noter(int $alerteId, string $type, ?AlerteDestinataire $destinataire = null, ?string $canal = null): void
    {
        try {
            $ref = null;
            if ($destinataire) {
                $ref = AlerteDestinataire::where('alerte_id', $alerteId)->where('id', '<=', $destinataire->id)->count();
            }
            static::create([
                'alerte_id' => $alerteId,
                'type' => $type,
                'destinataire_ref' => $ref,
                'canal' => $canal,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
