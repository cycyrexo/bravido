<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Etablissement */
class EtablissementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributs = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'categorie' => $this->whenLoaded('categorie', fn () => [
                'code' => $this->categorie->code,
                'libelle' => $this->categorie->libelle,
            ]),
            'commune' => $this->whenLoaded('commune', fn () => $this->commune->nom),
            'ville' => $this->whenLoaded('commune', fn () => $this->commune->relationLoaded('ville') ? $this->commune->ville->nom : null),
            'adresse' => $this->adresse,
            'repere' => $this->repere,
            'description' => $this->description,
            'lat' => $this->lat,
            'lng' => $this->lng,
            // true : lat/lng = centre de la commune (annuaire importé sans GPS)
            'position_approx' => ! $this->position_precise,
            'tel1' => $this->tel1,
            'tel2' => $this->tel2,
            'tel_autres' => $this->tel_autres,
            'email' => $this->email,
            'site_web' => $this->site_web,
            'responsable' => $this->responsable_nom,
            'horaires' => $this->horaires,
            'h24' => $this->h24,
            'premium' => $this->premium,
            'verifie_at' => $this->verifie_at?->toIso8601String(),
            'distance_m' => $this->when(
                isset($attributs['distance_m']), // NULL (position approximative) : clé absente, jamais « 0 m »
                fn () => (int) round((float) $attributs['distance_m'])
            ),
            'garde' => $this->when(
                array_key_exists('garde_debut', $attributs),
                fn () => ['du' => $attributs['garde_debut'], 'au' => $attributs['garde_fin']]
            ),
            'assurances' => $this->whenLoaded('assurances', fn () => $this->assurances->map(fn ($a) => [
                'id' => $a->id,
                'nom' => $a->nom,
            ])),
            'services' => $this->whenLoaded('services', fn () => $this->services->pluck('service')),
            'de_garde' => $this->when(
                array_key_exists('de_garde', $attributs) || array_key_exists('garde_debut', $attributs),
                fn () => array_key_exists('garde_debut', $attributs) || (bool) $attributs['de_garde']
            ),
            'nb_assurances' => $this->when(
                array_key_exists('assurances_count', $attributs),
                fn () => (int) $attributs['assurances_count']
            ),
            'gardes' => $this->whenLoaded('gardes', fn () => $this->gardes->map(fn ($g) => [
                'du' => $g->date_debut->toDateString(),
                'au' => $g->date_fin->toDateString(),
            ])),
        ];
    }
}
