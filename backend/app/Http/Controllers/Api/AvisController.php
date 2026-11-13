<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Avis;
use App\Models\AvisSignalement;
use App\Models\Etablissement;
use App\Services\AvisModeration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F13 : avis et notes. Pré-modération : un avis (ou une réponse) n'est public qu'après validation au back-office.
 * Aucun avis sur les établissements d'urgence (centres de secours, bureaux FGA).
 */
class AvisController extends Controller
{
    /** Catégories sans avis : l'urgence ne se note pas. */
    public const CATEGORIES_SANS_AVIS = EtablissementController::CATEGORIES_URGENCE;

    public const COMMENTAIRE_MAX = 500;

    public function __construct(private readonly AvisModeration $moderation) {}

    public static function avisActifs(Etablissement $e): bool
    {
        $e->loadMissing('categorie:id,code');

        return ! in_array($e->categorie?->code, self::CATEGORIES_SANS_AVIS, true);
    }

    /** GET /etablissements/{id}/avis — public : avis approuvés, moyenne, répartition (+ mon avis si connecté). */
    public function index(Request $request, Etablissement $etablissement): JsonResponse
    {
        abort_unless($etablissement->statut === 'publie', 404);

        if (! self::avisActifs($etablissement)) {
            return response()->json(['data' => [], 'meta' => ['actif' => false, 'moyenne' => null, 'total' => 0, 'repartition' => (object) [], 'mon_avis' => null]]);
        }

        $base = Avis::query()->approuves()->where('etablissement_id', $etablissement->id);
        $total = (clone $base)->count();
        $moyenne = $total > 0 ? round((float) (clone $base)->avg('note'), 1) : null;
        $repartition = (clone $base)->selectRaw('note, COUNT(*) AS n')->groupBy('note')->pluck('n', 'note');

        $liste = (clone $base)->with('auteur:id,nom')->latest('avis.created_at')->latest('avis.id')->paginate(10)->withQueryString();

        $mien = null;
        if ($user = auth('sanctum')->user()) {
            $a = Avis::where('etablissement_id', $etablissement->id)->where('user_id', $user->id)->first();
            $mien = $a ? $this->versMien($a) : null;
        }

        return response()->json([
            'data' => collect($liste->items())->map(fn (Avis $a) => [
                'id' => $a->id,
                'note' => $a->note,
                'commentaire' => $a->commentaire,
                'auteur' => $a->nomPublic(),
                'date' => $a->created_at->toDateString(),
                'reponse' => $a->reponsePublique(),
            ])->values(),
            'meta' => [
                'actif' => true,
                'moyenne' => $moyenne,
                'total' => $total,
                'repartition' => (object) collect(range(5, 1))->mapWithKeys(fn ($n) => [(string) $n => (int) ($repartition[$n] ?? 0)])->all(),
                'mon_avis' => $mien,
                'current_page' => $liste->currentPage(),
                'last_page' => $liste->lastPage(),
            ],
        ]);
    }

    /** POST /etablissements/{id}/mon-avis — un seul avis par utilisateur et par établissement. */
    public function store(Request $request, Etablissement $etablissement): JsonResponse
    {
        $this->verifierEtablissement($request, $etablissement);
        $data = $this->valider($request);

        if (Avis::where('etablissement_id', $etablissement->id)->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'Vous avez déjà donné votre avis sur cet établissement. Modifiez-le plutôt.'], 409);
        }

        $avis = new Avis($data);
        $avis->etablissement_id = $etablissement->id;
        $avis->user_id = $request->user()->id;
        $avis->statut = 'en_attente';
        $avis->drapeaux = $this->moderation->analyser($data['commentaire'] ?? null) ?: null;
        $avis->save();

        return response()->json(['data' => $this->versMien($avis)], 201);
    }

    /** PUT /etablissements/{id}/mon-avis — toute modification repasse en modération. */
    public function update(Request $request, Etablissement $etablissement): JsonResponse
    {
        $this->verifierEtablissement($request, $etablissement);
        $data = $this->valider($request);
        $avis = $this->monAvis($request, $etablissement);

        $avis->fill($data);
        $avis->statut = 'en_attente';
        $avis->motif_rejet = null;
        $avis->modere_par = null;
        $avis->modere_at = null;
        $avis->drapeaux = $this->moderation->analyser($data['commentaire'] ?? null) ?: null;
        // L'ancienne réponse du responsable portait sur l'ancien texte : elle n'est plus affichée
        $avis->reponse_statut = $avis->reponse ? 'en_attente' : null;
        $avis->save();

        return response()->json(['data' => $this->versMien($avis)]);
    }

    /** DELETE /etablissements/{id}/mon-avis */
    public function destroy(Request $request, Etablissement $etablissement): JsonResponse
    {
        $this->monAvis($request, $etablissement)->delete();

        return response()->json(['ok' => true]);
    }

    /** GET /mes-avis — mes avis avec leur état de modération (Espace utilisateur). */
    public function mesAvis(Request $request): JsonResponse
    {
        $avis = Avis::where('user_id', $request->user()->id)
            ->with(['etablissement:id,nom,categorie_id,commune_id', 'etablissement.categorie:id,code,libelle', 'etablissement.commune:id,nom'])
            ->latest('updated_at')->limit(100)->get();

        return response()->json(['data' => $avis->map(fn (Avis $a) => $this->versMien($a) + [
            'etablissement' => [
                'id' => $a->etablissement->id,
                'nom' => $a->etablissement->nom,
                'categorie' => ['code' => $a->etablissement->categorie->code, 'libelle' => $a->etablissement->categorie->libelle],
                'commune' => $a->etablissement->commune?->nom,
            ],
        ])->values()]);
    }

    /** POST /avis/{avis}/signaler — un avis publié signalé repasse en file de modération. */
    public function signaler(Request $request, Avis $avis): JsonResponse
    {
        abort_unless($avis->statut === 'approuve', 404);
        abort_if($avis->user_id === $request->user()->id, 403, 'Vous ne pouvez pas signaler votre propre avis.');
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:200']]);

        $nouveau = AvisSignalement::firstOrCreate(
            ['avis_id' => $avis->id, 'user_id' => $request->user()->id],
            ['motif' => $data['motif'] ?? null]
        );

        if ($nouveau->wasRecentlyCreated) {
            $drapeaux = array_values(array_unique([...($avis->drapeaux ?? []), 'signale']));
            $avis->forceFill(['statut' => 'en_attente', 'drapeaux' => $drapeaux, 'modere_par' => null, 'modere_at' => null])->save();
        }

        return response()->json(['message' => 'Merci, l\'avis sera examiné par notre équipe.']);
    }

    /** GET /mon-etablissement/{etablissement}/avis — le responsable voit les avis publiés (pour y répondre). */
    public function pourResponsable(Request $request, Etablissement $etablissement): JsonResponse
    {
        abort_unless($etablissement->responsable_id === $request->user()->id, 403);

        $avis = Avis::approuves()->where('etablissement_id', $etablissement->id)->with('auteur:id,nom')->latest('avis.created_at')->limit(50)->get();

        return response()->json(['data' => $avis->map(fn (Avis $a) => [
            'id' => $a->id,
            'note' => $a->note,
            'commentaire' => $a->commentaire,
            'auteur' => $a->nomPublic(),
            'date' => $a->created_at->toDateString(),
            'reponse' => $a->reponse,
            'reponse_statut' => $a->reponse_statut,
            'reponse_motif_rejet' => $a->reponse_statut === 'rejetee' ? $a->reponse_motif_rejet : null,
        ])->values()]);
    }

    /** POST /mon-etablissement/avis/{avis}/reponse — une seule réponse publique par avis, modérée. */
    public function repondre(Request $request, Avis $avis): JsonResponse
    {
        $avis->loadMissing('etablissement');
        abort_unless($avis->etablissement->responsable_id === $request->user()->id, 403, 'Réservé au responsable de l\'établissement.');
        abort_unless($avis->statut === 'approuve', 409, 'Vous ne pouvez répondre qu\'à un avis publié.');
        $data = $request->validate(['texte' => ['required', 'string', 'min:2', 'max:'.self::COMMENTAIRE_MAX]]);

        $avis->forceFill([
            'reponse' => trim($data['texte']),
            'reponse_statut' => 'en_attente',
            'reponse_motif_rejet' => null,
            'reponse_drapeaux' => $this->moderation->analyser($data['texte']) ?: null,
            'reponse_par' => $request->user()->id,
            'reponse_at' => now(),
        ])->save();

        return response()->json(['data' => ['id' => $avis->id, 'reponse' => $avis->reponse, 'reponse_statut' => $avis->reponse_statut]], 201);
    }

    // ---- internes ----

    private function valider(Request $request): array
    {
        $data = $request->validate([
            'note' => ['required', 'integer', 'between:1,5'],
            'commentaire' => ['nullable', 'string', 'max:'.self::COMMENTAIRE_MAX],
        ]);
        $data['commentaire'] = isset($data['commentaire']) && trim($data['commentaire']) !== '' ? trim($data['commentaire']) : null;

        return $data;
    }

    private function verifierEtablissement(Request $request, Etablissement $etablissement): void
    {
        abort_unless($etablissement->statut === 'publie', 404);
        abort_unless(self::avisActifs($etablissement), 403, 'Les établissements d\'urgence ne peuvent pas être notés.');
        abort_if($etablissement->responsable_id === $request->user()->id, 403, 'Vous ne pouvez pas noter l\'établissement que vous gérez.');
    }

    private function monAvis(Request $request, Etablissement $etablissement): Avis
    {
        return Avis::where('etablissement_id', $etablissement->id)->where('user_id', $request->user()->id)->firstOrFail();
    }

    /** Vue « mon avis » : inclut l'état de modération (jamais visible des autres). */
    private function versMien(Avis $a): array
    {
        return [
            'id' => $a->id,
            'note' => $a->note,
            'commentaire' => $a->commentaire,
            'statut' => $a->statut,
            'motif_rejet' => $a->statut === 'rejete' ? $a->motif_rejet : null,
            'date' => $a->created_at->toDateString(),
            'reponse' => $a->reponsePublique(),
        ];
    }
}
