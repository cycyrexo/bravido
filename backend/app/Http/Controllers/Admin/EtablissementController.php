<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Assurance;
use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\Ville;
use App\Support\Journal;
use App\Support\Telephone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** BO-02 : gestion des établissements. */
class EtablissementController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'categorie_id' => ['nullable', 'integer'],
            'ville_id' => ['nullable', 'integer'],
            'statut' => ['nullable', 'in:brouillon,publie'],
            'q' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'in:approx,precise'],
        ]);

        $query = Etablissement::with(['categorie', 'commune.ville']);
        // Annuaire importé sans GPS : file de travail « à géolocaliser »
        if (! empty($f['position'])) {
            $query->where('position_precise', $f['position'] === 'precise');
        }
        if (! empty($f['categorie_id'])) {
            $query->where('categorie_id', $f['categorie_id']);
        }
        if (! empty($f['ville_id'])) {
            $query->dansVille((int) $f['ville_id']);
        }
        if (! empty($f['statut'])) {
            $query->where('statut', $f['statut']);
        }
        if (! empty($f['q'])) {
            $query->where('nom', 'like', $this->like($f['q']));
        }

        return view('admin.etablissements.index', [
            'etablissements' => $query->orderBy('nom')->paginate(25)->withQueryString(),
            'categories' => Categorie::orderBy('ordre')->get(),
            'villes' => Ville::orderBy('nom')->get(),
            'f' => $f,
            'aGeolocaliser' => Etablissement::where('position_precise', false)->count(),
        ]);
    }

    public function create(): View
    {
        return $this->formulaire(new Etablissement(['statut' => 'brouillon', 'lat' => 5.3600, 'lng' => -4.0083]));
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $services, $assurances] = $this->valider($request);

        $etab = DB::transaction(function () use ($data, $services, $assurances) {
            $etab = Etablissement::create($data);
            $etab->syncServices($services);
            $etab->assurances()->sync($assurances);

            return $etab;
        });

        Journal::log('etablissement.creation', $etab, null, $etab->toArray());

        return redirect()->route('admin.etablissements.edit', $etab)->with('succes', 'Établissement créé.');
    }

    public function edit(Etablissement $etablissement): View
    {
        return $this->formulaire($etablissement->load(['services', 'assurances']));
    }

    public function update(Request $request, Etablissement $etablissement): RedirectResponse
    {
        [$data, $services, $assurances] = $this->valider($request);
        $avant = $etablissement->only(array_keys($data));

        DB::transaction(function () use ($etablissement, $data, $services, $assurances) {
            $etablissement->update($data);
            $etablissement->syncServices($services);
            $etablissement->assurances()->sync($assurances);
        });

        Journal::log('etablissement.modification', $etablissement, $avant, $etablissement->only(array_keys($data)));

        return back()->with('succes', 'Modifications enregistrées.');
    }

    public function basculerStatut(Etablissement $etablissement): RedirectResponse
    {
        $nouveau = $etablissement->statut === 'publie' ? 'brouillon' : 'publie';
        $etablissement->update(['statut' => $nouveau]);
        Journal::log($nouveau === 'publie' ? 'etablissement.publication' : 'etablissement.depublication', $etablissement);

        return back()->with('succes', $nouveau === 'publie' ? 'Établissement publié.' : 'Établissement dépublié.');
    }

    public function verifier(Etablissement $etablissement): RedirectResponse
    {
        $etablissement->update(['verifie_at' => now()]);
        Journal::log('etablissement.verification', $etablissement);

        return back()->with('succes', 'Date de vérification mise à jour.');
    }

    public function destroy(Etablissement $etablissement): RedirectResponse
    {
        Journal::log('etablissement.suppression', $etablissement, $etablissement->toArray());
        $etablissement->delete();

        return redirect()->route('admin.etablissements.index')->with('succes', 'Établissement supprimé.');
    }

    private function formulaire(Etablissement $etablissement): View
    {
        return view('admin.etablissements.form', [
            'etablissement' => $etablissement,
            'categories' => Categorie::orderBy('ordre')->get(),
            'villes' => Ville::with('communes')->orderBy('nom')->get(),
            'assurances' => Assurance::where('actif', true)->orderBy('nom')->get(),
            'servicesProposes' => config('bravido.services_etablissement'),
        ]);
    }

    /** @return array{0: array, 1: string[], 2: int[]} */
    private function valider(Request $request): array
    {
        $data = $request->validate([
            'categorie_id' => ['required', 'integer', 'exists:categories,id'],
            'nom' => ['required', 'string', 'max:200'],
            'commune_id' => ['required', 'integer', 'exists:communes,id'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'repere' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'tel1' => ['nullable', 'string', 'max:20'],
            'tel2' => ['nullable', 'string', 'max:20'],
            'tel_autres' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'site_web' => ['nullable', 'string', 'max:255'],
            'responsable_nom' => ['nullable', 'string', 'max:150'],
            'horaires' => ['nullable', 'string', 'max:255'],
            'statut' => ['required', 'in:brouillon,publie'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'max:100'],
            'services_autres' => ['nullable', 'string', 'max:500'],
            'assurances' => ['nullable', 'array'],
            'assurances.*' => ['integer', 'exists:assurances,id'],
        ]);

        $services = array_merge(
            $data['services'] ?? [],
            array_map('trim', explode(',', $data['services_autres'] ?? ''))
        );
        $assurances = $data['assurances'] ?? [];
        unset($data['services'], $data['services_autres'], $data['assurances']);

        $data['h24'] = $request->boolean('h24');
        // Décochée = lat/lng approximatifs (centre de commune) : l'app n'affiche pas de distance
        $data['position_precise'] = $request->boolean('position_precise');
        $data['premium'] = $request->boolean('premium');
        foreach (['tel1', 'tel2'] as $champ) {
            if (! empty($data[$champ])) {
                $data[$champ] = Telephone::normaliser($data[$champ]) ?? $data[$champ];
            }
        }
        if ($request->boolean('marquer_verifie')) {
            $data['verifie_at'] = now();
        }

        return [$data, $services, $assurances];
    }
}
