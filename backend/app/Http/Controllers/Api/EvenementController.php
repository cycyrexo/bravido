<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Services\EtabStats;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** F17 : compteurs d'usage d'une fiche (vue, appel, itinéraire). Anonyme : aucun identifiant n'est enregistré. */
class EvenementController extends Controller
{
    public function store(Request $request, Etablissement $etablissement, EtabStats $stats): Response
    {
        $data = $request->validate(['type' => ['required', 'in:vue,appel,itineraire']]);
        abort_unless($etablissement->statut === 'publie', 404);

        $stats->enregistrer($etablissement, $data['type']);

        return response()->noContent();
    }
}
