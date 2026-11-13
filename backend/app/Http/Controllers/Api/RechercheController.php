<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Services\Recherche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** F10 : recherche globale (nom, services, catégories, synonymes français). */
class RechercheController extends Controller
{
    /** GET /recherche?q=&ville_id=&lat=&lng=&categorie= */
    public function index(Request $request, Recherche $recherche)
    {
        $f = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'ville_id' => ['nullable', 'integer'],
            'categorie' => ['nullable', 'string', 'max:200'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);

        $r = $recherche->chercher($f['q'], $f);

        return EtablissementResource::collection($r['items'])->additional([
            'meta' => [
                'total' => $r['total'],
                'max' => Recherche::MAX_RESULTATS,
                'groupes' => $r['groupes'],
                'interprete' => $r['interprete'],
            ],
        ]);
    }

    /** GET /recherche/suggestions?q= — au plus 8 suggestions */
    public function suggestions(Request $request, Recherche $recherche): JsonResponse
    {
        $f = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json(['data' => $recherche->suggestions($f['q'])]);
    }
}
