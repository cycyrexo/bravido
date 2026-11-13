<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FicheMedicale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FicheMedicaleController extends Controller
{
    /** GET /me/fiche-medicale — toujours la fiche du propriétaire uniquement */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fiche($request)->versApi()]);
    }

    /** PUT /me/fiche-medicale */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'groupe_sanguin' => ['nullable', Rule::in(User::GROUPES_SANGUINS)],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'traitements' => ['nullable', 'string', 'max:2000'],
            'pathologies' => ['nullable', 'string', 'max:2000'],
            'personne_a_prevenir_nom' => ['nullable', 'string', 'max:150'],
            'personne_a_prevenir_tel' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'partage' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        if (array_key_exists('groupe_sanguin', $data)) {
            $user->forceFill(['groupe_sanguin' => $data['groupe_sanguin']])->save();
            unset($data['groupe_sanguin']);
        }

        $fiche = $this->fiche($request);
        $fiche->fill($data)->save();

        return response()->json(['data' => $fiche->versApi()]);
    }

    private function fiche(Request $request): FicheMedicale
    {
        $user = $request->user();
        $fiche = FicheMedicale::firstOrNew(["user_id" => $user->id]);
        if (! $fiche->exists) {
            $fiche->forceFill(["user_id" => $user->id]);
        }
        $fiche->setRelation('user', $user);

        return $fiche;
    }
}
