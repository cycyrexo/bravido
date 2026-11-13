<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlerteResource;
use App\Models\Alerte;
use App\Models\AlerteDestinataire;
use App\Models\AlerteEvenement;
use App\Services\AlerteService;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AlerteController extends Controller
{
    public function __construct(private readonly AlerteService $service) {}

    /** POST /alertes — URG-02/03 (le compte à rebours annulable est géré par l'app avant l'appel) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'motif' => ['required', Rule::in(array_keys(Alerte::MOTIFS))],
            // La position est facultative : l'alerte part même sans GPS
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'precision_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $alerte = $this->service->declencher($request->user(), $data);
        $alerte->load(['destinataires', 'dernierePosition', 'audios']);

        return (new AlerteResource($alerte))->response()->setStatusCode(201);
    }

    /** GET /alertes — historique de mes alertes */
    public function index(Request $request): AnonymousResourceCollection
    {
        return AlerteResource::collection(
            // Avantage non critique (F19) : historique étendu pour les abonnés ; lecture seule, sans effet sur l'envoi
            $request->user()->alertes()->latest('started_at')->limit($request->user()->abonnementEnCours() ? 100 : 20)->get()
        );
    }

    /** GET /alertes/{id} — vue propriétaire (suivi) ou vue complice (position) */
    public function show(Request $request, Alerte $alerte): AlerteResource
    {
        $userId = $request->user()->id;

        if ($alerte->user_id === $userId) {
            $alerte->load(['destinataires', 'dernierePosition', 'audios']);
        } else {
            abort_unless($alerte->destinataires()->where('destinataire_user_id', $userId)->exists(), 404);
            $alerte->load(['user', 'audios']);
            // La position n'est visible des complices que pendant l'alerte
            if ($alerte->estActive()) {
                $alerte->load('dernierePosition');
            }
        }

        return new AlerteResource($alerte);
    }

    /** POST /alertes/{id}/positions — toutes les 30 s pendant 1 h */
    public function position(Request $request, Alerte $alerte): JsonResponse
    {
        abort_unless($alerte->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'precision_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if (! $alerte->estActive()) {
            if ($alerte->statut === 'active') {
                $this->service->terminer($alerte, 'close', 'expiree');
            }

            return response()->json(['message' => 'Cette alerte est terminée.', 'statut' => 'close'], 409);
        }

        $alerte->positions()->create($data + ['recorded_at' => now()]);

        return response()->json(['statut' => 'active'], 201);
    }

    public function cloturer(Request $request, Alerte $alerte): AlerteResource
    {
        abort_unless($alerte->user_id === $request->user()->id, 404);

        return new AlerteResource($this->service->terminer($alerte, 'close')->load('destinataires'));
    }

    public function annuler(Request $request, Alerte $alerte): AlerteResource
    {
        abort_unless($alerte->user_id === $request->user()->id, 404);

        return new AlerteResource($this->service->terminer($alerte, 'annulee')->load('destinataires'));
    }

    /** GET /alertes-recues — alertes actives de mes proches */
    public function recues(Request $request): AnonymousResourceCollection
    {
        $alertes = Alerte::query()
            ->where('statut', 'active')
            ->where('expires_at', '>', now())
            ->whereHas('destinataires', fn ($q) => $q->where('destinataire_user_id', $request->user()->id))
            ->with(['user', 'dernierePosition', 'audios'])
            ->latest('started_at')
            ->get();

        return AlerteResource::collection($alertes);
    }

    /** POST /alertes/{id}/accuse — appelé par l'app à la réception du push (stoppe le SMS de secours) */
    public function accuse(Request $request, Alerte $alerte): JsonResponse
    {
        $destinataire = $this->destinataire($request, $alerte);
        if (! $destinataire->recu_at) {
            $destinataire->forceFill(['recu_at' => now()])->save();
            AlerteEvenement::noter($alerte->id, 'recue', $destinataire, 'app');
        }

        return response()->json(['etat' => $destinataire->etat()]);
    }

    /** POST /alertes/{id}/vu */
    public function vu(Request $request, Alerte $alerte): JsonResponse
    {
        $destinataire = $this->destinataire($request, $alerte);
        $this->noterLecture($alerte, $destinataire);
        $destinataire->forceFill([
            'recu_at' => $destinataire->recu_at ?? now(),
            'vu_at' => $destinataire->vu_at ?? now(),
        ])->save();

        return response()->json(['etat' => $destinataire->etat()]);
    }

    /** POST /alertes/{id}/reponse — « j'arrive » / « j'appelle les secours » */
    public function reponse(Request $request, Alerte $alerte, Notifier $notifier): JsonResponse
    {
        $data = $request->validate(['reponse' => ['required', Rule::in(['j_arrive', 'appelle_secours'])]]);
        $destinataire = $this->destinataire($request, $alerte);

        $this->noterLecture($alerte, $destinataire);
        AlerteEvenement::noter($alerte->id, 'reponse', $destinataire, 'app');
        $destinataire->forceFill([
            'recu_at' => $destinataire->recu_at ?? now(),
            'vu_at' => $destinataire->vu_at ?? now(),
            'reponse' => $data['reponse'],
            'repondu_at' => now(),
        ])->save();

        $texte = $data['reponse'] === 'j_arrive' ? 'arrive' : 'appelle les secours';
        $notifier->push($alerte->user, 'BRAVIDO', "{$destinataire->nom} {$texte}.", [
            'type' => 'alerte_reponse',
            'alerte_id' => $alerte->id,
        ]);

        return response()->json(['etat' => $destinataire->etat()]);
    }

    /** Journal d'audit : « reçue » et « vue » ne sont notés qu'à la première fois. */
    private function noterLecture(Alerte $alerte, AlerteDestinataire $destinataire): void
    {
        if (! $destinataire->recu_at) {
            AlerteEvenement::noter($alerte->id, 'recue', $destinataire, 'app');
        }
        if (! $destinataire->vu_at) {
            AlerteEvenement::noter($alerte->id, 'vue', $destinataire, 'app');
        }
    }

    private function destinataire(Request $request, Alerte $alerte): AlerteDestinataire
    {
        return $alerte->destinataires()
            ->where('destinataire_user_id', $request->user()->id)
            ->firstOr(fn () => abort(404));
    }
}
