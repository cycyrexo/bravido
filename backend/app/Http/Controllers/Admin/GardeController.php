<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\Garde;
use App\Models\ImportLot;
use App\Models\Ville;
use App\Services\ImportGardes;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** BO-03 : programme de garde. */
class GardeController extends Controller
{
    public function index(): View
    {
        return view('admin.gardes.index', [
            'lots' => ImportLot::with(['ville', 'auteur'])->latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        // Par défaut : la semaine suivante, de samedi à vendredi (à ajuster selon le programme officiel)
        $debut = today()->next(Carbon::SATURDAY);

        return view('admin.gardes.create', [
            'villes' => Ville::where('actif', true)->orderBy('nom')->get(),
            'debut' => $debut->toDateString(),
            'fin' => $debut->copy()->addDays(6)->toDateString(),
        ]);
    }

    public function store(Request $request, ImportGardes $import): RedirectResponse
    {
        $data = $request->validate([
            'ville_id' => ['required', 'integer', 'exists:villes,id'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'fichier' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        $lot = $import->analyser(
            $request->file('fichier'),
            Ville::findOrFail($data['ville_id']),
            Carbon::parse($data['date_debut']),
            Carbon::parse($data['date_fin']),
            $request->user()->id
        );

        Journal::log('gardes.import', $lot, null, ['valides' => $lot->nb_valides, 'erreurs' => $lot->nb_erreurs]);

        return redirect()->route('admin.gardes.show', $lot);
    }

    public function show(ImportLot $lot): View
    {
        return view('admin.gardes.show', ['lot' => $lot->load(['ville', 'auteur'])]);
    }

    public function publier(ImportLot $lot, ImportGardes $import): RedirectResponse
    {
        abort_unless($lot->statut === 'apercu', 409, 'Ce lot a déjà été traité.');

        $crees = $import->publier($lot);
        Journal::log('gardes.publication', $lot, null, ['gardes_creees' => $crees]);

        return redirect()->route('admin.gardes.show', $lot)->with('succes', "{$crees} garde(s) publiée(s).");
    }

    /** Lot en aperçu : abandon. Lot publié : retrait de ses gardes (erreur de programme). */
    public function annuler(ImportLot $lot): RedirectResponse
    {
        abort_if($lot->statut === 'annule', 409, 'Ce lot est déjà annulé.');

        $retirees = DB::transaction(function () use ($lot) {
            $n = $lot->gardes()->delete();
            $lot->update(['statut' => 'annule']);

            return $n;
        });

        Journal::log('gardes.annulation', $lot, null, ['gardes_retirees' => $retirees]);

        return redirect()->route('admin.gardes.index')->with('succes', "Lot annulé ({$retirees} garde(s) retirée(s)).");
    }

    /** Maquette « Programme de garde » : période + pharmacies à cocher. */
    public function saisie(Request $request): View
    {
        $villes = Ville::where('actif', true)->orderBy('nom')->get();
        $f = $request->validate([
            'ville_id' => ['nullable', 'integer'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date'],
        ]);

        $ville = $villes->firstWhere('id', (int) ($f['ville_id'] ?? 0)) ?? $villes->first();
        $debut = isset($f['date_debut']) ? Carbon::parse($f['date_debut']) : today()->next(Carbon::SATURDAY);
        $fin = isset($f['date_fin']) ? Carbon::parse($f['date_fin']) : $debut->copy()->addDays(6);

        $pharmacies = $ville
            ? Etablissement::deCategorie('pharmacie')->publie()->dansVille($ville->id)->with('commune')
                ->orderBy('nom')->get()
            : collect();

        // Pharmacies déjà de garde sur la période (cochées par défaut)
        $deGarde = Garde::whereIn('etablissement_id', $pharmacies->pluck('id'))
            ->chevauche($debut->toDateString(), $fin->toDateString())
            ->pluck('etablissement_id')->all();

        return view('admin.gardes.saisie', compact('villes', 'ville', 'debut', 'fin', 'pharmacies', 'deGarde'));
    }

    public function enregistrerSaisie(Request $request, ImportGardes $import): RedirectResponse
    {
        $data = $request->validate([
            'ville_id' => ['required', 'integer', 'exists:villes,id'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'etablissements' => ['nullable', 'array'],
            'etablissements.*' => ['integer'],
        ]);
        $debut = Carbon::parse($data['date_debut'])->toDateString();
        $fin = Carbon::parse($data['date_fin'])->toDateString();

        $pharmacies = Etablissement::deCategorie('pharmacie')->dansVille((int) $data['ville_id'])->with('commune')->get()->keyBy('id');
        $coches = collect($data['etablissements'] ?? [])->map(fn ($id) => (int) $id)->filter(fn ($id) => $pharmacies->has($id))->unique();

        $retirees = DB::transaction(function () use ($pharmacies, $coches, $debut, $fin) {
            // Décochées : on retire leur garde sur exactement cette période
            return Garde::whereIn('etablissement_id', $pharmacies->keys()->diff($coches))
                ->where('date_debut', $debut)->where('date_fin', $fin)
                ->delete();
        });

        // Les pharmacies déjà de garde sur la période restent telles quelles :
        // on ne crée un lot que pour les nouvelles (évite des lots « doublon » à chaque réenregistrement).
        $dejaDeGarde = Garde::whereIn('etablissement_id', $coches)->chevauche($debut, $fin)->pluck('etablissement_id')->all();
        $lignes = $coches->reject(fn (int $id) => in_array($id, $dejaDeGarde))->values()
            ->map(function (int $id, int $i) use ($pharmacies, $debut, $fin) {
                $e = $pharmacies[$id];

                return [
                    'ligne' => $i + 1, 'nom' => $e->nom, 'commune' => $e->commune->nom,
                    'etablissement_id' => $id, 'etablissement_nom' => $e->nom.' ('.$e->commune->nom.')',
                    'date_debut' => $debut, 'date_fin' => $fin,
                    'statut' => 'ok', 'message' => null,
                ];
            })->all();

        $crees = 0;
        if ($lignes !== []) {
            $valides = count(array_filter($lignes, fn ($l) => $l['statut'] === 'ok'));
            $lot = ImportLot::create([
                'ville_id' => $data['ville_id'], 'date_debut' => $debut, 'date_fin' => $fin,
                'fichier_nom' => 'Saisie manuelle', 'statut' => 'apercu', 'lignes' => $lignes,
                'nb_valides' => $valides, 'nb_erreurs' => count($lignes) - $valides, 'created_by' => $request->user()->id,
            ]);
            $crees = $import->publier($lot);
            Journal::log('gardes.saisie', $lot, null, ['gardes_creees' => $crees, 'gardes_retirees' => $retirees]);
        }

        return redirect()
            ->route('admin.gardes.saisie', ['ville_id' => $data['ville_id'], 'date_debut' => $debut, 'date_fin' => $fin])
            ->with('succes', "Programme publié : {$crees} garde(s) ajoutée(s), {$retirees} retirée(s).");
    }

    public function modele(): StreamedResponse
    {
        return response()->streamDownload(function () {
            // BOM UTF-8 + séparateur « ; » : s'ouvre correctement dans Excel FR
            echo "\xEF\xBB\xBF";
            echo "id;nom;commune;date_debut;date_fin\n";
            echo ";Pharmacie Exemple;Cocody;;\n";
            echo "12;;;;\n";
        }, 'modele-programme-garde.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
