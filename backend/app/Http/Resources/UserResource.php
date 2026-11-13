<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'telephone' => $this->telephone,
            'nom' => $this->nom,
            'ville_id' => $this->ville_id,
            'ville' => $this->whenLoaded('ville', fn () => $this->ville?->nom),
            'groupe_sanguin' => $this->groupe_sanguin,
            'role' => $this->role,
            // F18 : nombre d'établissements dont l'utilisateur est le responsable validé
            'nb_etablissements' => \App\Models\Etablissement::where('responsable_id', $this->id)->count(),
        ];
    }
}
