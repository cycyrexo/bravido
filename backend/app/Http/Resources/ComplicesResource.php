<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Complice */
class ComplicesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'telephone' => $this->telephone,
            'statut' => $this->statut,
            'ordre' => $this->ordre,
            'a_l_application' => $this->complice_user_id !== null,
            'repondu_at' => $this->repondu_at?->toIso8601String(),
            // Vue « invitations reçues » : la personne qui invite
            'proprietaire' => $this->whenLoaded('user', fn () => [
                'nom' => $this->user->nomAffiche(),
                'telephone' => $this->user->telephone,
            ]),
        ];
    }
}
