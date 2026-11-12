<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fiche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** USR-04 : fiches de souscription et de correction (validées au back-office, BO-04). */
class FicheController extends Controller
{
    public const CHAMPS = [
        'nom', 'categorie_code', 'commune_id', 'adresse', 'repere', 'description',
        'lat', 'lng', 'tel1', 'tel2', 'horaires', 'h24', 'services', 'assurances',
    ];

    public function index(Request $request): JsonResponse
    {
        $fiches = Fiche::where('soumis_par', $request->user()->id)
            ->with('etablissement:id,nom')
            ->latest()
            ->get();

        return response()->json(['data' => $fiches->map(fn (Fiche $f) => $this->format($f))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:souscription,correction'],
            'etablissement_id' => ['nullable', 'required_if:type,correction', 'integer', 'exists:etablissements,id'],
            'donnees' => ['required', 'array'],
            'donnees.nom' => ['nullable', 'required_if:type,souscription', 'string', 'max:200'],
            'donnees.categorie_code' => ['nullable', 'required_if:type,souscription', 'string', 'exists:categories,code'],
            'donnees.commune_id' => ['nullable', 'required_if:type,souscription', 'integer', 'exists:communes,id'],
            'donnees.adresse' => ['nullable', 'string', 'max:255'],
            'donnees.repere' => ['nullable', 'string', 'max:255'],
            'donnees.description' => ['nullable', 'string', 'max:2000'],
            'donnees.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'donnees.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'donnees.tel1' => ['nullable', 'string', 'max:20'],
            'donnees.tel2' => ['nullable', 'string', 'max:20'],
            'donnees.horaires' => ['nullable', 'string', 'max:255'],
            'donnees.h24' => ['nullable', 'boolean'],
            'donnees.services' => ['nullable', 'array', 'max:30'],
            'donnees.services.*' => ['string', 'max:100'],
            'donnees.assurances' => ['nullable', 'array', 'max:50'],
            'donnees.assurances.*' => ['integer', 'exists:assurances,id'],
            'pieces' => ['nullable', 'array', 'max:3'],
            'pieces.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $donnees = array_filter(
            Arr::only($data['donnees'], self::CHAMPS),
            fn ($v) => $v !== null && $v !== ''
        );
        if (isset($donnees['h24'])) {
            $donnees['h24'] = filter_var($donnees['h24'], FILTER_VALIDATE_BOOLEAN);
        }

        // Pièces jointes : disque privé, jamais servies publiquement
        $pieces = [];
        foreach ($request->file('pieces', []) as $fichier) {
            $pieces[] = [
                'chemin' => $fichier->store('fiches', 'local'),
                'nom' => $fichier->getClientOriginalName(),
            ];
        }

        $fiche = new Fiche([
            'type' => $data['type'],
            'etablissement_id' => $data['type'] === 'correction' ? $data['etablissement_id'] : null,
            'donnees' => $donnees,
            'pieces' => $pieces ?: null,
        ]);
        $fiche->soumis_par = $request->user()->id;
        $fiche->statut = 'en_attente';
        $fiche->save();

        return response()->json(['data' => $this->format($fiche->load('etablissement:id,nom'))], 201);
    }

    private function format(Fiche $f): array
    {
        return [
            'id' => $f->id,
            'type' => $f->type,
            'etablissement' => $f->etablissement?->only(['id', 'nom']),
            'nom' => $f->donnees['nom'] ?? $f->etablissement?->nom,
            'statut' => $f->statut,
            'motif_rejet' => $f->motif_rejet,
            'created_at' => $f->created_at->toIso8601String(),
            'traite_at' => $f->traite_at?->toIso8601String(),
        ];
    }
}
