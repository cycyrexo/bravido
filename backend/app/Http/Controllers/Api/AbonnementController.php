<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\AbonnementMembre;
use App\Models\Paiement;
use App\Models\Plan;
use App\Models\User;
use App\Services\Paiement\PaiementNonConfigureException;
use App\Services\Paiement\SignatureInvalideException;
use App\Services\PaiementService;
use App\Support\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * F19 : abonnement famille. RÈGLE PRODUIT : l'alerte (push, SMS de secours, positions, trajets) reste gratuite et
 * identique pour tous ; l'abonnement n'ouvre que des avantages non critiques.
 */
class AbonnementController extends Controller
{
    public const MESSAGE_ALERTES_GRATUITES = 'Les alertes d\'urgence restent gratuites et identiques pour tous, abonnés ou non.';

    public function __construct(private readonly PaiementService $paiements) {}

    /** GET /plans — public. */
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => Plan::where('actif', true)->orderBy('prix_xof')->get()->map(fn (Plan $p) => $this->plan($p))->values(),
            'fournisseurs' => $this->fournisseurs(),
            'note' => self::MESSAGE_ALERTES_GRATUITES,
        ]);
    }

    /** GET /abonnement — mon abonnement (propriétaire ou membre), places, avantages, derniers paiements. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $a = $user->abonnementEnCours();

        $data = ['actif' => $a !== null, 'note' => self::MESSAGE_ALERTES_GRATUITES, 'fournisseurs' => $this->fournisseurs()];
        if ($a) {
            $a->load('membres.user:id,nom,telephone');
            $proprio = $a->user_id === $user->id;
            $data += [
                'abonnement' => [
                    'id' => $a->id,
                    'plan' => $this->plan($a->plan),
                    'fin' => $a->fin->toDateString(),
                    'role' => $proprio ? 'proprietaire' : 'membre',
                    'places_utilisees' => $a->profilsOccupes(),
                    'places_max' => $a->plan->profils_max,
                ],
                'membres' => $a->membres->map(fn (AbonnementMembre $m) => [
                    'id' => $m->id,
                    'nom' => $m->user->nomAffiche(),
                    'telephone' => $proprio ? $m->user->telephone : null,
                    'statut' => $m->statut,
                ])->values(),
                'avantages' => $this->avantages($a),
            ];
        }
        $data['paiements'] = Paiement::where('user_id', $user->id)->with('plan:id,nom')->latest('id')->limit(5)->get()->map(fn (Paiement $p) => [
            'reference' => $p->reference, 'plan' => $p->plan->nom, 'montant' => $p->montant, 'devise' => $p->devise,
            'fournisseur' => $p->fournisseur, 'statut' => $p->statut, 'date' => $p->created_at->toDateString(),
        ])->values();

        return response()->json(['data' => $data]);
    }

    /** POST /abonnement/souscrire {plan, fournisseur} → URL de paiement chez le fournisseur. */
    public function souscrire(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,code'],
            'fournisseur' => ['required', 'string', 'in:sandbox,orange_money,mtn_momo,wave'],
        ]);

        try {
            $r = $this->paiements->souscrire($request->user(), Plan::where('code', $data['plan'])->firstOrFail(), $data['fournisseur']);
        } catch (PaiementNonConfigureException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'non_configure'], 503);
        }

        return response()->json(['data' => [
            'reference' => $r['paiement']->reference,
            'montant' => $r['paiement']->montant,
            'devise' => $r['paiement']->devise,
            'url' => $r['url'],
        ]], 201);
    }

    /** POST /paiements/webhook/{fournisseur} — public, signature obligatoire, idempotent par référence. */
    public function webhook(Request $request, string $fournisseur): JsonResponse
    {
        $entetes = collect($request->headers->all())->map(fn ($v) => (string) ($v[0] ?? ''))->all();

        try {
            $r = $this->paiements->traiterWebhook($fournisseur, $request->getContent(), $entetes);
        } catch (SignatureInvalideException) {
            Log::warning('Webhook paiement refusé (signature)', ['fournisseur' => $fournisseur, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Signature invalide.'], 401);
        } catch (PaiementNonConfigureException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Fournisseur inconnu.'], 404);
        }

        return response()->json($r);
    }

    // ---- Membres de la famille ----

    /** POST /abonnement/membres {telephone} — le propriétaire invite un proche déjà inscrit. */
    public function inviter(Request $request): JsonResponse
    {
        $a = $request->user()->abonnementEnCours();
        abort_unless($a && $a->user_id === $request->user()->id, 403, 'Seul le titulaire d\'un abonnement actif peut inviter des membres.');
        $data = $request->validate(['telephone' => ['required', 'string', 'max:30']]);

        $tel = Telephone::normaliser($data['telephone']);
        $invite = $tel ? User::where('telephone', $tel)->first() : null;
        if (! $invite) {
            return response()->json(['message' => 'Aucun compte BRAVIDO avec ce numéro. Invitez cette personne à installer l\'application d\'abord.'], 422);
        }
        abort_if($invite->id === $a->user_id, 422, 'Vous faites déjà partie de l\'abonnement.');
        if ($a->membres()->where('user_id', $invite->id)->exists()) {
            return response()->json(['message' => 'Cette personne est déjà invitée.'], 409);
        }
        if ($a->profilsOccupes() >= $a->plan->profils_max) {
            return response()->json(['message' => "Limite atteinte : ce plan inclut {$a->plan->profils_max} profils au total."], 422);
        }

        $m = new AbonnementMembre;
        $m->abonnement_id = $a->id;
        $m->user_id = $invite->id;
        $m->statut = 'invite';
        $m->save();

        return response()->json(['data' => ['id' => $m->id, 'statut' => 'invite']], 201);
    }

    /** GET /abonnement/invitations — invitations reçues. */
    public function invitations(Request $request): JsonResponse
    {
        $liste = AbonnementMembre::where('user_id', $request->user()->id)->where('statut', 'invite')
            ->whereHas('abonnement', fn ($q) => $q->enCours())->with('abonnement.proprietaire:id,nom')->get();

        return response()->json(['data' => $liste->map(fn (AbonnementMembre $m) => [
            'id' => $m->id, 'de' => $m->abonnement->proprietaire->nomAffiche(),
        ])->values()]);
    }

    public function accepter(Request $request, AbonnementMembre $membre): JsonResponse
    {
        abort_unless($membre->user_id === $request->user()->id && $membre->abonnement->estActif(), 404);
        $membre->forceFill(['statut' => 'accepte'])->save();

        return response()->json(['data' => ['id' => $membre->id, 'statut' => 'accepte']]);
    }

    /** DELETE /abonnement/membres/{membre} — retire un membre (propriétaire) ou quitte l'abonnement (membre). */
    public function retirer(Request $request, AbonnementMembre $membre): JsonResponse
    {
        $uid = $request->user()->id;
        abort_unless($membre->user_id === $uid || $membre->abonnement->user_id === $uid, 404);
        $membre->delete();

        return response()->json(['ok' => true]);
    }

    // ---- internes ----

    private function plan(Plan $p): array
    {
        return [
            'code' => $p->code, 'nom' => $p->nom, 'prix_xof' => $p->prix_xof, 'duree_jours' => $p->duree_jours,
            'profils_max' => $p->profils_max, 'a_confirmer' => $p->a_confirmer,
        ];
    }

    /** @return list<array{code: string, libelle: string, actif: bool}> */
    private function fournisseurs(): array
    {
        return collect($this->paiements->fournisseurs())->map(fn ($f) => [
            'code' => $f->code(), 'libelle' => $f->libelle(), 'disponible' => $f->configure(),
        ])->values()->all();
    }

    /** Avantages non critiques de l'abonnement. */
    private function avantages(Abonnement $a): array
    {
        return [
            'profils_famille' => $a->plan->profils_max,
            'historique_etendu' => true,
            'support_prioritaire' => true,
        ];
    }
}
