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
            'premium' => $this->estPremium(),
            // Contenus réservés aux fiches Premium en cours de validité
            'description_longue' => $this->when($this->estPremium() && $this->description_longue, fn () => $this->description_longue),
            'horaires_detail' => $this->when($this->estPremium() && ! empty($this->horaires_detail), fn () => collect(\App\Http\Controllers\Api\MonEtablissementController::JOURS)
                ->filter(fn ($j) => isset($this->horaires_detail[$j]))->mapWithKeys(fn ($j) => [$j => $this->horaires_detail[$j]])),
            'photos' => $this->when($this->estPremium() && $this->relationLoaded('photos'), fn () => $this->photos->map(fn ($p) => [
                'url' => $p->url(),
                'id' => $p->id,
                'miniature' => $p->urlMiniature(),
            ])->values()),
            // « À la une » : créneau sponsorisé en tête d'une liste (jamais pour les urgences)
            'a_la_une' => $this->when(array_key_exists('a_la_une', $attributs), fn () => (bool) $attributs['a_la_une']),
            // F13 : avis publiés (note moyenne sur 5 et nombre) ; la clé est absente quand les avis sont désactivés
            'avis' => $this->when(array_key_exists('avis_nombre', $attributs), fn () => [
                'nombre' => (int) $attributs['avis_nombre'],
                'moyenne' => (int) $attributs['avis_nombre'] > 0 ? round((float) $attributs['avis_moyenne'], 1) : null,
            ]),
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
