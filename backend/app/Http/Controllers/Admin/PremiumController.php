<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\MonEtablissementController;
use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\EtabPhoto;
use App\Services\EtabStats;
use App\Services\PhotosEtablissement;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** F17 : fiches Premium (abonnement, contenus enrichis, statistiques d'usage sur 30 jours). */
class PremiumController extends Controller
{
    public function index(Request $request, EtabStats $stats): View
    {
        $q = trim((string) $request->query('q', ''));

        $premium = Etablissement::where('premium', true)->with(['categorie', 'commune.ville'])->withCount('photos')
            ->orderByRaw('premium_jusqu_au is null')->orderBy('premium_jusqu_au')->orderBy('nom')->paginate(25)->withQueryString();

        $resultats = $q === '' ? collect() : Etablissement::where('premium', false)->where('nom', 'like', '%'.addcslashes($q, '%_\\').'%')
            ->with(['categorie', 'commune.ville'])->orderBy('nom')->limit(10)->get();

        return view('admin.premium.index', [
            'premium' => $premium,
            'resumes' => $stats->resumes($premium->pluck('id')->all()),
            'q' => $q,
            'resultats' => $resultats,
        ]);
    }

    public function edit(Etablissement $etablissement, EtabStats $stats): View
    {
        $etablissement->load(['categorie', 'commune.ville', 'photos']);

        return view('admin.premium.edit', [
            'e' => $etablissement,
            'stats' => $stats->resume($etablissement->id),
            'jours' => MonEtablissementController::JOURS,
            'photosMax' => PhotosEtablissement::MAX,
        ]);
    }

    public function update(Request $request, Etablissement $etablissement): RedirectResponse
    {
        $data = $request->validate([
            'premium_jusqu_au' => ['nullable', 'date'],
            'description_longue' => ['nullable', 'string', 'max:4000'],
            'jours' => ['nullable', 'array'],
            'jours.*' => ['nullable', 'string', 'max:60'],
        ]);

        $jours = [];
        foreach (MonEtablissementController::JOURS as $j) {
            $v = trim((string) ($data['jours'][$j] ?? ''));
            if ($v !== '') {
                $jours[$j] = $v;
            }
        }

        $avant = $etablissement->only(['premium', 'premium_jusqu_au', 'description_longue', 'horaires_detail']);
        $etablissement->forceFill([
            'premium' => $request->boolean('premium'),
            'premium_jusqu_au' => $data['premium_jusqu_au'] ?? null,
            'description_longue' => $data['description_longue'] ?? null,
            'horaires_detail' => $jours ?: null,
        ])->save();

        Journal::log('etablissement.premium', $etablissement, $avant,
            $etablissement->only(['premium', 'premium_jusqu_au', 'description_longue', 'horaires_detail']));

        return back()->with('succes', 'Fiche Premium enregistrée.');
    }

    /** Activation rapide depuis la recherche de la liste (date facultative). */
    public function activer(Request $request, Etablissement $etablissement): RedirectResponse
    {
        $data = $request->validate(['premium_jusqu_au' => ['nullable', 'date']]);
        $avant = $etablissement->only(['premium', 'premium_jusqu_au']);
        $etablissement->forceFill(['premium' => true, 'premium_jusqu_au' => $data['premium_jusqu_au'] ?? null])->save();
        Journal::log('etablissement.premium', $etablissement, $avant, $etablissement->only(['premium', 'premium_jusqu_au']));

        return redirect()->route('admin.premium.edit', $etablissement)->with('succes', 'Premium activé : complétez la fiche.');
    }

    public function ajouterPhoto(Request $request, Etablissement $etablissement, PhotosEtablissement $photos): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);
        try {
            $photos->ajouter($etablissement, $request->file('photo'));
        } catch (\DomainException $e) {
            return back()->withErrors($e->getMessage());
        }
        Journal::log('etablissement.photo_ajoutee', $etablissement);

        return back()->with('succes', 'Photo ajoutée.');
    }

    public function supprimerPhoto(Etablissement $etablissement, EtabPhoto $photo, PhotosEtablissement $photos): RedirectResponse
    {
        abort_unless($photo->etablissement_id === $etablissement->id, 404);
        $photos->supprimer($photo);
        Journal::log('etablissement.photo_supprimee', $etablissement);

        return back()->with('succes', 'Photo supprimée.');
    }
}
