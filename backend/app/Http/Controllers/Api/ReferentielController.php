<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assurance;
use App\Models\Categorie;
use App\Models\NumeroUrgence;
use App\Models\Ville;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ReferentielController extends Controller
{
    /**
     * GET /config — tout ce que l'app met en cache hors ligne au démarrage
     * (numéros d'urgence, villes, catégories, assurances).
     */
    public function config(): JsonResponse
    {
        $data = Cache::remember('api_config', 300, fn () => [
            'villes' => Ville::where('actif', true)
                ->with('communes:id,ville_id,nom')
                ->orderBy('nom')
                ->get(['id', 'nom', 'region', 'lat', 'lng']),
            'categories' => Categorie::where('actif', true)->orderBy('ordre')->get(['id', 'code', 'libelle', 'module']),
            'assurances' => Assurance::where('actif', true)->orderBy('nom')->get(['id', 'nom', 'type']),
            'numeros_urgence' => NumeroUrgence::where('actif', true)->orderBy('ordre')->get(['id', 'libelle', 'numero', 'description']),
            'services' => config('bravido.services_etablissement'),
            'call_center' => config('bravido.call_center'),
            'complices_max' => config('bravido.complices_max'),
            'alerte' => [
                'intervalle_position_s' => config('bravido.alerte.intervalle_position_s'),
                'duree_minutes' => config('bravido.alerte.duree_minutes'),
            ],
        ]);

        return response()->json($data + ['genere_at' => now()->toIso8601String()]);
    }
}
