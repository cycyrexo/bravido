<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Models\Etablissement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EtablissementController extends Controller
{
    // Les cartes de liste affichent commune · services et « N assurances » (maquettes)
    private const RELATIONS_LISTE = [
        'categorie:id,code,libelle', 'commune:id,ville_id,nom', 'commune.ville:id,nom',
        'services:id,etablissement_id,service',
    ];

    private const RAYON_DEFAUT_KM = 25;

    /** GET /etablissements — HOP-01, HOP-02, URG-06, URG-07 */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtres = $this->validerFiltres($request);

        return EtablissementResource::collection(
            $this->construire($filtres)->paginate($filtres['par_page'] ?? 30)->withQueryString()
        );
    }

    /** GET /pharmacies — PHA-01, PHA-04 */
    public function pharmacies(Request $request): AnonymousResourceCollection
    {
        $filtres = $this->validerFiltres($request);
        $filtres['categorie'] = 'pharmacie';

        return EtablissementResource::collection(
            $this->construire($filtres)->paginate($filtres['par_page'] ?? 30)->withQueryString()
        );
    }

    /** GET /pharmacies/garde — PHA-02 : gardes de la période en cours, la plus proche en premier */
    public function gardes(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'ville_id' => ['required_without:lat', 'nullable', 'integer'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'rayon_km' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'assurance_id' => ['nullable', 'integer'],
        ]);

        $jour = today()->toDateString();

        $query = Etablissement::query()
            ->publie()
            ->deCategorie('pharmacie')
            ->join('gardes', 'gardes.etablissement_id', '=', 'etablissements.id')
            ->where('gardes.date_debut', '<=', $jour)
            ->where('gardes.date_fin', '>=', $jour)
            ->select('etablissements.*', 'gardes.date_debut as garde_debut', 'gardes.date_fin as garde_fin')
            ->with([...self::RELATIONS_LISTE, 'assurances:id,nom'])
            ->withCount('assurances');

        $aPosition = isset($f['lat'], $f['lng']);

        if (! empty($f['ville_id'])) {
            $query->dansVille((int) $f['ville_id']);
        } elseif ($aPosition) {
            $query->dansRayon((float) $f['lat'], (float) $f['lng'], (float) ($f['rayon_km'] ?? 30));
        }

        if (! empty($f['assurance_id'])) {
            $query->whereHas('assurances', fn (Builder $q) => $q->where('assurances.id', $f['assurance_id']));
        }

        $aPosition
            ? $query->avecDistance((float) $f['lat'], (float) $f['lng'])->triParDistance()
            : $query->orderBy('etablissements.nom');

        $items = $query->limit(300)->get();
        $premier = $items->first();

        return EtablissementResource::collection($items)->additional([
            'periode' => $premier ? ['du' => $premier->garde_debut, 'au' => $premier->garde_fin] : null,
            'genere_at' => now()->toIso8601String(),
        ]);
    }

    /** GET /etablissements/{id} — PHA-03, HOP-03 */
    public function show(Etablissement $etablissement): EtablissementResource
    {
        abort_unless($etablissement->statut === 'publie', 404);

        $etablissement->load([
            'categorie', 'commune.ville', 'assurances', 'services',
            'gardes' => fn ($q) => $q->where('date_fin', '>=', today()->toDateString())->orderBy('date_debut')->limit(3),
        ]);

        return new EtablissementResource($etablissement);
    }

    private function validerFiltres(Request $request): array
    {
        return $request->validate([
            'categorie' => ['nullable', 'string', 'max:200'], // codes séparés par des virgules
            'ville_id' => ['nullable', 'integer'],
            'commune_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'string', 'max:100'],
            'assurance_id' => ['nullable', 'integer'],
            'h24' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'rayon_km' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'par_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function construire(array $f): Builder
    {
        $query = Etablissement::query()->publie()->with(self::RELATIONS_LISTE)->withCount('assurances')
            // Badge « De garde » aussi dans l'onglet « Toutes les pharmacies »
            ->withExists(['gardes as de_garde' => fn (Builder $q) => $q->enCours()]);

        if (! empty($f['categorie'])) {
            $query->deCategorie(array_filter(array_map('trim', explode(',', $f['categorie']))));
        }

        if (! empty($f['commune_id'])) {
            $query->where('etablissements.commune_id', $f['commune_id']);
        } elseif (! empty($f['ville_id'])) {
            $query->dansVille((int) $f['ville_id']);
        }

        if (! empty($f['q'])) {
            $query->where('etablissements.nom', 'like', $this->like($f['q']));
        }

        if (! empty($f['service'])) {
            $query->whereHas('services', fn (Builder $q) => $q->where('service', $f['service']));
        }

        if (! empty($f['assurance_id'])) {
            $query->whereHas('assurances', fn (Builder $q) => $q->where('assurances.id', $f['assurance_id']));
        }

        if (! empty($f['h24'])) {
            $query->where('etablissements.h24', true);
        }

        if (isset($f['lat'], $f['lng'])) {
            $lat = (float) $f['lat'];
            $lng = (float) $f['lng'];
            $sansZone = empty($f['commune_id']) && empty($f['ville_id']);
            if ($sansZone || ! empty($f['rayon_km'])) {
                $query->dansRayon($lat, $lng, (float) ($f['rayon_km'] ?? self::RAYON_DEFAUT_KM));
            }
            $query->avecDistance($lat, $lng)->triParDistance();
        } else {
            $query->orderBy('etablissements.nom');
        }

        return $query;
    }
}
