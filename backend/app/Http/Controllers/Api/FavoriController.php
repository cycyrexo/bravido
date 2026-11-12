<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Models\Etablissement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** PHA-05 (V2, livré car peu coûteux) */
class FavoriController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $favoris = $request->user()->favoris()
            ->where('etablissements.statut', 'publie')
            ->with(['categorie:id,code,libelle', 'commune:id,ville_id,nom', 'commune.ville:id,nom'])
            ->orderBy('etablissements.nom')
            ->get();

        return EtablissementResource::collection($favoris);
    }

    public function store(Request $request): Response
    {
        $data = $request->validate(['etablissement_id' => ['required', 'integer', 'exists:etablissements,id']]);
        $request->user()->favoris()->syncWithoutDetaching([$data['etablissement_id']]);

        return response()->noContent();
    }

    public function destroy(Request $request, Etablissement $etablissement): Response
    {
        $request->user()->favoris()->detach($etablissement->id);

        return response()->noContent();
    }
}
