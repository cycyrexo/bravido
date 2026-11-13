<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Services\Geolocalisation;
use App\Services\PositionsUtilisateurs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** F2 : « Je suis sur place : confirmer la position » (position approximative d'un établissement importé). */
class PositionProposeeController extends Controller
{
    public function store(Request $request, Etablissement $etablissement, PositionsUtilisateurs $positions, Geolocalisation $geo): JsonResponse
    {
        abort_unless($etablissement->statut === 'publie', 404);

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'precision_m' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        if ($etablissement->position_precise) {
            return response()->json(['message' => 'La position de cet établissement est déjà vérifiée.'], 409);
        }
        if ($positions->dejaPropose($etablissement, $request->user())) {
            return response()->json(['message' => 'Vous avez déjà confirmé la position de cet établissement. Merci !'], 409);
        }
        if ($data['precision_m'] > PositionsUtilisateurs::PRECISION_MAX_M) {
            return response()->json(['message' => 'Signal GPS trop imprécis ('.PositionsUtilisateurs::PRECISION_MAX_M." m maximum). Placez-vous à l'extérieur et réessayez."], 422);
        }

        $etablissement->loadMissing('commune.ville');
        if (! $geo->dansRayonVille((float) $data['lat'], (float) $data['lng'], $geo->centreVille($etablissement))) {
            return response()->json(['message' => "Cette position est trop éloignée de la ville de l'établissement."], 422);
        }

        $positions->enregistrer($etablissement, $request->user(), (float) $data['lat'], (float) $data['lng'], (int) round($data['precision_m']));

        return response()->json(['message' => 'Merci ! Votre position sera vérifiée avant publication.'], 201);
    }
}
