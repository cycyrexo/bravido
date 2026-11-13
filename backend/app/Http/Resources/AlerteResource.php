<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Alerte */
class AlerteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $estProprietaire = $request->user()?->id === $this->user_id;

        return [
            'id' => $this->id,
            'motif' => $this->motif,
            'motif_libelle' => $this->libelleMotif(),
            'statut' => $this->estActive() ? 'active' : ($this->statut === 'active' ? 'close' : $this->statut),
            'started_at' => $this->started_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'lien_carte' => $this->when($this->estActive(), fn () => $this->lienCarte()),
            'intervalle_position_s' => config('bravido.alerte.intervalle_position_s'),
            'position' => $this->whenLoaded('dernierePosition', fn () => $this->dernierePosition ? [
                'lat' => $this->dernierePosition->lat,
                'lng' => $this->dernierePosition->lng,
                'precision_m' => $this->dernierePosition->precision_m,
                'recorded_at' => $this->dernierePosition->recorded_at->toIso8601String(),
            ] : null),
            // Vue complice : qui a besoin d'aide
            'personne' => $this->when(! $estProprietaire && $this->relationLoaded('user'), fn () => [
                'nom' => $this->user->nomAffiche(),
                'telephone' => $this->user->telephone,
                'groupe_sanguin' => $this->user->groupe_sanguin,
            ]),
            // Fiche médicale : uniquement si opt-in + alerte active + complice accepté (sinon null)
            'fiche_medicale' => $this->when(! $estProprietaire && $this->relationLoaded('user'), fn () => $this->ficheMedicalePour($request->user())?->versApi()),
            // Messages vocaux (propriétaire et destinataires acceptés)
            'audios' => $this->when($this->relationLoaded('audios'), fn () => $this->audios->map(fn ($a) => [
                'id' => $a->id,
                'duree_s' => $a->duree_s,
                'created_at' => $a->created_at->toIso8601String(),
            ])->values()),
            // Vue propriétaire : suivi par complice (URG-04)
            'destinataires' => $this->when($estProprietaire && $this->relationLoaded('destinataires'), fn () => $this->destinataires->map(fn ($d) => [
                'id' => $d->id,
                'nom' => $d->nom,
                'telephone' => $d->telephone,
                'etat' => $d->etat(),
                'canal_sms' => $d->sms_envoye_at !== null,
                'repondu_at' => $d->repondu_at?->toIso8601String(),
            ])),
        ];
    }
}
