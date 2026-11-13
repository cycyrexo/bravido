<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complice;
use App\Models\Trajet;
use App\Services\TrajetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrajetController extends Controller
{
    public function __construct(private readonly TrajetService $service) {}

    /** POST /trajets */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'duree_minutes' => ['required', 'integer', 'between:'.config('bravido.trajet.duree_min_minutes').','.config('bravido.trajet.duree_max_minutes')],
            'complice_ids' => ['nullable', 'array', 'max:'.config('bravido.complices_max')],
            'complice_ids.*' => ['integer'],
            'partage_position' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'precision_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $existant = $this->trajetActif($request);
        if ($existant) {
            return response()->json(['message' => 'Un trajet est déjà en cours.', 'data' => $this->vers($existant)], 409);
        }

        $trajet = $this->service->demarrer($request->user(), $data);

        return response()->json(['data' => $this->vers($trajet)], 201);
    }

    /** GET /trajets/en-cours — trajet actif (ou escaladé en alerte encore active) ; sinon data = null */
    public function enCours(Request $request): JsonResponse
    {
        $trajet = $this->trajetActif($request);

        return response()->json(['data' => $trajet ? $this->vers($trajet) : null]);
    }

    /** POST /trajets/{trajet}/positions — toutes les 60 s si le partage est activé */
    public function position(Request $request, Trajet $trajet): JsonResponse
    {
        $this->proprietaire($request, $trajet);
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'precision_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if ($trajet->statut === 'arrive') {
            return response()->json(['message' => 'Ce trajet est terminé.', 'statut' => 'arrive'], 409);
        }
        $this->service->enregistrerPosition($trajet, (float) $data['lat'], (float) $data['lng'], $data['precision_m'] ?? null);

        return response()->json(['statut' => $trajet->statut], 201);
    }

    /** POST /trajets/{trajet}/arrive — « je suis arrivé » : annule la surveillance */
    public function arrive(Request $request, Trajet $trajet): JsonResponse
    {
        $this->proprietaire($request, $trajet);

        return response()->json(['data' => $this->vers($this->service->arriver($trajet->load('user', 'alerte')))]);
    }

    /** POST /trajets/{trajet}/prolonger — +15 min, 2 fois maximum */
    public function prolonger(Request $request, Trajet $trajet): JsonResponse
    {
        $this->proprietaire($request, $trajet);

        if (! $this->service->prolonger($trajet)) {
            return response()->json(['message' => 'Prolongation impossible (limite atteinte ou trajet terminé).', 'data' => $this->vers($trajet)], 409);
        }

        return response()->json(['data' => $this->vers($trajet->refresh())]);
    }

    /** GET /trajets-suivis — trajets en cours de mes proches qui m'ont choisi pour les suivre */
    public function suivis(Request $request): JsonResponse
    {
        $liens = Complice::where('complice_user_id', $request->user()->id)->where('statut', 'accepte')->get();
        $trajets = collect();
        foreach ($liens as $lien) {
            $trajets = $trajets->merge(
                Trajet::where('user_id', $lien->user_id)->where('statut', 'en_cours')->with('user')->get()
                    ->filter(fn (Trajet $t) => $t->complice_ids === null || in_array($lien->id, $t->complice_ids, true))
            );
        }

        return response()->json(['data' => $trajets->map(fn (Trajet $t) => [
            'id' => $t->id,
            'personne' => $t->user->nomAffiche(),
            'echeance_at' => $t->echeance_at->toIso8601String(),
            // Position partagée uniquement si la personne a activé le partage
            'position' => $t->partage_position && $t->lat !== null ? [
                'lat' => $t->lat, 'lng' => $t->lng, 'recorded_at' => $t->position_at?->toIso8601String(),
            ] : null,
        ])->values()]);
    }

    private function trajetActif(Request $request): ?Trajet
    {
        return Trajet::where('user_id', $request->user()->id)
            ->where(fn ($q) => $q->where('statut', 'en_cours')
                ->orWhere(fn ($q2) => $q2->where('statut', 'alerte')
                    ->whereHas('alerte', fn ($a) => $a->where('statut', 'active')->where('expires_at', '>', now()))))
            ->latest('id')
            ->first();
    }

    private function proprietaire(Request $request, Trajet $trajet): void
    {
        abort_unless($trajet->user_id === $request->user()->id, 404);
    }

    private function vers(Trajet $trajet): array
    {
        $complices = Complice::where('user_id', $trajet->user_id)
            ->when($trajet->complice_ids, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get(['id', 'nom']);

        return [
            'id' => $trajet->id,
            'statut' => $trajet->statut,
            'started_at' => $trajet->started_at->toIso8601String(),
            'echeance_at' => $trajet->echeance_at->toIso8601String(),
            'serveur_now' => now()->toIso8601String(),
            'grace_minutes' => config('bravido.trajet.grace_minutes'),
            'extensions' => $trajet->extensions,
            'extensions_restantes' => max(0, config('bravido.trajet.prolongations_max') - $trajet->extensions),
            'prolongation_minutes' => config('bravido.trajet.prolongation_minutes'),
            'partage_position' => $trajet->partage_position,
            'intervalle_position_s' => config('bravido.trajet.intervalle_position_s'),
            'complices' => $complices->map(fn ($c) => ['id' => $c->id, 'nom' => $c->nom])->values(),
            'alerte_id' => $trajet->alerte_id,
            'arrive_at' => $trajet->arrive_at?->toIso8601String(),
        ];
    }
}
