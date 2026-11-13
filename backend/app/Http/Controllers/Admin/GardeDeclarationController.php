<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Garde;
use App\Models\GardeDeclaration;
use App\Services\DeclarationsGarde;
use App\Services\Notifier;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** F18 : modération des gardes déclarées par les responsables de pharmacie. */
class GardeDeclarationController extends Controller
{
    public function __construct(private readonly DeclarationsGarde $service, private readonly Notifier $notifier) {}

    public function index(Request $request): View
    {
        $statut = $request->validate(['statut' => ['nullable', 'in:en_attente,validee,rejetee']])['statut'] ?? 'en_attente';

        $declarations = GardeDeclaration::with(['etablissement.commune.ville', 'auteur'])
            ->where('statut', $statut)->latest('id')->paginate(25)->withQueryString();

        // Contexte : autres pharmacies déjà de garde sur la période dans la même ville
        $autres = [];
        foreach ($declarations as $d) {
            $villeId = $d->etablissement->commune->ville_id;
            $autres[$d->id] = Garde::chevauche($d->date_debut->toDateString(), $d->date_fin->toDateString())
                ->where('etablissement_id', '!=', $d->etablissement_id)
                ->whereHas('etablissement.commune', fn ($q) => $q->where('ville_id', $villeId))
                ->distinct()->count('etablissement_id');
        }

        return view('admin.gardes.declarations', compact('declarations', 'statut', 'autres'));
    }

    public function valider(Request $request, GardeDeclaration $declaration): RedirectResponse
    {
        try {
            $garde = $this->service->valider($declaration->load('etablissement'), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors($e->getMessage());
        }
        Journal::log('garde.declaration_validee', $declaration, null, $garde->only(['etablissement_id', 'date_debut', 'date_fin']));
        $this->notifier->push($declaration->auteur, 'BRAVIDO', 'Votre garde du '.$declaration->date_debut->format('d/m').' au '.$declaration->date_fin->format('d/m').' est publiée.', [
            'type' => 'garde_declaration', 'declaration_id' => $declaration->id,
        ]);

        return back()->with('succes', 'Garde publiée.');
    }

    public function rejeter(Request $request, GardeDeclaration $declaration): RedirectResponse
    {
        $data = $request->validate(['motif_rejet' => ['required', 'string', 'max:500']]);
        try {
            $this->service->rejeter($declaration, $request->user(), $data['motif_rejet']);
        } catch (\DomainException $e) {
            return back()->withErrors($e->getMessage());
        }
        Journal::log('garde.declaration_rejetee', $declaration, null, $data);
        $this->notifier->push($declaration->auteur, 'BRAVIDO', 'Votre déclaration de garde n\'a pas été retenue : '.$data['motif_rejet'], [
            'type' => 'garde_declaration', 'declaration_id' => $declaration->id,
        ]);

        return back()->with('succes', 'Déclaration rejetée.');
    }
}
