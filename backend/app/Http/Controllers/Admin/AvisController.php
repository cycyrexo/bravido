<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avis;
use App\Services\AvisModeration;
use App\Services\Notifier;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** F13 : modération des avis et des réponses des responsables (pré-modération : rien n'est public avant validation). */
class AvisController extends Controller
{
    public function __construct(private readonly Notifier $notifier, private readonly AvisModeration $moderation) {}

    public function index(Request $request): View
    {
        $onglet = $request->validate(['onglet' => ['nullable', 'in:en_attente,reponses,approuve,rejete']])['onglet'] ?? 'en_attente';

        $requete = Avis::query()->with(['etablissement:id,nom,commune_id', 'etablissement.commune:id,nom', 'auteur:id,nom,telephone', 'signalements']);
        match ($onglet) {
            'reponses' => $requete->where('statut', 'approuve')->where('reponse_statut', 'en_attente')->orderBy('reponse_at'),
            'approuve' => $requete->where('statut', 'approuve')->latest('modere_at'),
            'rejete' => $requete->where('statut', 'rejete')->latest('modere_at'),
            default => $requete->where('statut', 'en_attente')->orderByRaw('(drapeaux IS NOT NULL) DESC')->orderBy('created_at'),
        };

        return view('admin.avis.index', [
            'avis' => $requete->paginate(25)->withQueryString(),
            'onglet' => $onglet,
            'compteurs' => self::compteurs(),
            'moderation' => $this->moderation,
        ]);
    }

    /** Avis en attente + réponses en attente (badge du menu). */
    public static function compteurs(): array
    {
        return [
            'en_attente' => Avis::where('statut', 'en_attente')->count(),
            'reponses' => Avis::where('statut', 'approuve')->where('reponse_statut', 'en_attente')->count(),
        ];
    }

    public function valider(Avis $avis): RedirectResponse
    {
        $avant = $avis->only(['statut']);
        $avis->forceFill(['statut' => 'approuve', 'motif_rejet' => null, 'modere_par' => auth()->id(), 'modere_at' => now()])->save();
        Journal::log('avis.validation', $avis, $avant, ['statut' => 'approuve']);
        $this->notifier->push($avis->auteur, 'BRAVIDO', 'Votre avis est publié. Merci !', ['type' => 'avis', 'avis_id' => $avis->id]);

        return back()->with('succes', 'Avis publié.');
    }

    public function rejeter(Request $request, Avis $avis): RedirectResponse
    {
        $data = $request->validate(['motif_rejet' => ['required', 'string', 'max:500']]);
        $avant = $avis->only(['statut']);
        $avis->forceFill(['statut' => 'rejete', 'motif_rejet' => $data['motif_rejet'], 'modere_par' => auth()->id(), 'modere_at' => now()])->save();
        Journal::log('avis.rejet', $avis, $avant, $data);
        $this->notifier->push($avis->auteur, 'BRAVIDO', "Votre avis n'a pas pu être publié : {$data['motif_rejet']}", ['type' => 'avis', 'avis_id' => $avis->id]);

        return back()->with('succes', 'Avis rejeté.');
    }

    public function validerReponse(Avis $avis): RedirectResponse
    {
        abort_unless($avis->reponse_statut === 'en_attente', 409, 'Cette réponse a déjà été traitée.');
        $avis->forceFill(['reponse_statut' => 'approuvee', 'reponse_motif_rejet' => null, 'reponse_at' => now()])->save();
        Journal::log('avis.reponse_validation', $avis, null, ['reponse_statut' => 'approuvee']);

        return back()->with('succes', 'Réponse publiée.');
    }

    public function rejeterReponse(Request $request, Avis $avis): RedirectResponse
    {
        abort_unless($avis->reponse_statut === 'en_attente', 409, 'Cette réponse a déjà été traitée.');
        $data = $request->validate(['motif_rejet' => ['required', 'string', 'max:500']]);
        $avis->forceFill(['reponse_statut' => 'rejetee', 'reponse_motif_rejet' => $data['motif_rejet']])->save();
        Journal::log('avis.reponse_rejet', $avis, null, $data);
        if ($avis->reponse_par && ($resp = \App\Models\User::find($avis->reponse_par))) {
            $this->notifier->push($resp, 'BRAVIDO', "Votre réponse à un avis n'a pas pu être publiée : {$data['motif_rejet']}", ['type' => 'avis', 'avis_id' => $avis->id]);
        }

        return back()->with('succes', 'Réponse rejetée.');
    }
}
