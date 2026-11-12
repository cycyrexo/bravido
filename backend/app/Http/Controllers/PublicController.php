<?php

namespace App\Http\Controllers;

use App\Models\Alerte;
use App\Models\Complice;
use App\Services\ComplicesService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Pages ouvertes depuis un SMS par des complices qui n'ont pas l'application. */
class PublicController extends Controller
{
    /** Carte de l'alerte (lien du SMS de secours). Position visible seulement pendant l'alerte. */
    public function carte(string $token): View
    {
        $alerte = Alerte::where('lien_token', $token)->with(['user', 'dernierePosition'])->firstOrFail();

        return view('public.alerte', [
            'alerte' => $alerte,
            'active' => $alerte->estActive(),
        ]);
    }

    public function position(string $token): JsonResponse
    {
        $alerte = Alerte::where('lien_token', $token)->with('dernierePosition')->first();

        if (! $alerte || ! $alerte->estActive() || ! $alerte->dernierePosition) {
            return response()->json(['active' => (bool) $alerte?->estActive()]);
        }

        $p = $alerte->dernierePosition;

        return response()->json([
            'active' => true,
            'lat' => $p->lat,
            'lng' => $p->lng,
            'precision_m' => $p->precision_m,
            'recorded_at' => $p->recorded_at->toIso8601String(),
        ]);
    }

    public function invitation(string $token): View
    {
        $complice = Complice::where('invitation_token', $token)->with('user')->firstOrFail();

        return view('public.invitation', ['complice' => $complice]);
    }

    public function repondreInvitation(Request $request, string $token, ComplicesService $service): View|RedirectResponse
    {
        $data = $request->validate(['reponse' => ['required', 'in:accepter,refuser']]);
        $complice = Complice::where('invitation_token', $token)->with('user')->firstOrFail();
        $accepte = $data['reponse'] === 'accepter';

        try {
            $service->repondre($complice, $accepte);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return view('public.message', [
            'titre' => $accepte ? 'Merci !' : 'Invitation refusée',
            'texte' => $accepte
                ? "Vous serez prévenu(e) par SMS si {$complice->user->nomAffiche()} déclenche une alerte. Installez l'application BRAVIDO pour suivre sa position en temps réel."
                : "{$complice->user->nomAffiche()} sera informé(e) de votre réponse.",
        ]);
    }
}
