<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\Commune;
use App\Models\Etablissement;
use App\Models\Ville;
use App\Services\Geolocalisation;
use App\Services\PositionsUtilisateurs;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Assistant « À géolocaliser » : file des établissements dont la position n'est que le centre de la commune. */
class GeolocalisationController extends Controller
{
    private const PAR_PAGE = 10;

    private const SESSION_IGNORES = 'geo_ignores';

    public function index(Request $request, PositionsUtilisateurs $positions): View
    {
        $f = $request->validate([
            'ville_id' => ['nullable', 'integer'],
            'commune_id' => ['nullable', 'integer'],
            'categorie_id' => ['nullable', 'integer'],
        ]);
        $ignores = (array) $request->session()->get(self::SESSION_IGNORES, []);

        $base = $this->file($f);
        $total = (clone $base)->count();
        $restants = (clone $base)->whereNotIn('etablissements.id', $ignores)->count();

        $etablissements = $base->whereNotIn('etablissements.id', $ignores)
            ->with(['categorie', 'commune.ville'])
            // Les fiches publiées d'abord (visibles dans l'application), puis par commune
            ->orderByRaw("etablissements.statut = 'publie' desc")
            ->orderBy('etablissements.commune_id')->orderBy('etablissements.nom')
            ->limit(self::PAR_PAGE)->get();

        return view('admin.geolocalisation.index', [
            'propositions' => $this->propositions($positions),
            'etablissements' => $etablissements,
            'total' => $total,
            'restants' => $restants,
            'nbIgnores' => count($ignores),
            'f' => $f,
            'villes' => Ville::orderBy('nom')->get(),
            'communes' => ! empty($f['ville_id']) ? Commune::where('ville_id', $f['ville_id'])->orderBy('nom')->get() : collect(),
            'categories' => Categorie::orderBy('ordre')->get(),
            'perPage' => self::PAR_PAGE,
        ]);
    }

    /** Candidats Nominatim (JSON), appelés carte par carte pour respecter 1 requête/s. */
    public function candidats(Etablissement $etablissement, Geolocalisation $geo): JsonResponse
    {
        $r = $geo->candidats($etablissement);

        return response()->json($r);
    }

    public function valider(Request $request, Etablissement $etablissement, Geolocalisation $geo, PositionsUtilisateurs $positions): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $etablissement->load('commune.ville');

        if (! $geo->dansRayonVille((float) $data['lat'], (float) $data['lng'], $geo->centreVille($etablissement))) {
            return response()->json(['message' => 'Ce point est à plus de '.(int) Geolocalisation::RAYON_VILLE_KM.' km du centre de la ville : vérifiez-le.'], 422);
        }

        $avant = $etablissement->only(['lat', 'lng', 'position_precise', 'verifie_at']);
        $etablissement->forceFill([
            'lat' => round((float) $data['lat'], 7),
            'lng' => round((float) $data['lng'], 7),
            'position_precise' => true,
            'verifie_at' => now(),
        ])->save();
        $positions->purgerPour($etablissement); // propositions d'utilisateurs devenues sans objet

        Journal::log('etablissement.geolocalisation', $etablissement, $avant,
            $etablissement->only(['lat', 'lng', 'position_precise']));

        return response()->json([
            'ok' => true,
            'restants' => $this->restants($request),
        ]);
    }

    /** F2 : valide le point consensuel (médiane) des propositions d'utilisateurs. */
    public function validerProposition(Etablissement $etablissement, PositionsUtilisateurs $positions): RedirectResponse
    {
        $c = $positions->consensus($etablissement);
        if ($c === null) {
            return redirect()->route('admin.geolocalisation.index')->withErrors('Cette proposition n\'est plus valable (moins de 3 utilisateurs concordants).');
        }

        $avant = $etablissement->only(['lat', 'lng', 'position_precise', 'verifie_at']);
        $etablissement->forceFill([
            'lat' => $c['lat'], 'lng' => $c['lng'], 'position_precise' => true, 'verifie_at' => now(),
        ])->save();
        $positions->purgerPour($etablissement);

        Journal::log('etablissement.position_utilisateurs_validee', $etablissement, $avant,
            $etablissement->only(['lat', 'lng', 'position_precise']) + ['utilisateurs' => $c['nb']]);

        return redirect()->route('admin.geolocalisation.index')->with('succes', 'Position validée : '.$etablissement->nom.'.');
    }

    public function rejeterProposition(Etablissement $etablissement, PositionsUtilisateurs $positions): RedirectResponse
    {
        $n = $positions->purgerPour($etablissement);
        Journal::log('etablissement.position_utilisateurs_rejetee', $etablissement, ['propositions' => $n], null);

        return redirect()->route('admin.geolocalisation.index')->with('succes', 'Propositions rejetées et supprimées.');
    }

    /** Établissements signalés « position à valider » : point consensuel, sans aucune identité d'utilisateur. */
    private function propositions(PositionsUtilisateurs $positions): array
    {
        return Etablissement::whereNotNull('position_a_valider_at')->with(['categorie', 'commune.ville'])
            ->orderBy('position_a_valider_at')->get()
            ->map(fn (Etablissement $e) => ['etab' => $e, 'consensus' => $positions->consensus($e)])
            ->filter(fn ($p) => $p['consensus'] !== null)->values()->all();
    }

    public function ignorer(Request $request, Etablissement $etablissement): JsonResponse
    {
        $ignores = (array) $request->session()->get(self::SESSION_IGNORES, []);
        $ignores[] = $etablissement->id;
        $request->session()->put(self::SESSION_IGNORES, array_values(array_unique($ignores)));

        return response()->json(['ok' => true, 'restants' => $this->restants($request)]);
    }

    public function reinitialiserIgnores(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_IGNORES);

        return redirect()->route('admin.geolocalisation.index');
    }

    private function restants(Request $request): int
    {
        return Etablissement::where('position_precise', false)
            ->whereNotIn('id', (array) $request->session()->get(self::SESSION_IGNORES, []))->count();
    }

    private function file(array $f)
    {
        $q = Etablissement::query()->where('etablissements.position_precise', false);
        if (! empty($f['ville_id'])) {
            $q->dansVille((int) $f['ville_id']);
        }
        if (! empty($f['commune_id'])) {
            $q->where('etablissements.commune_id', $f['commune_id']);
        }
        if (! empty($f['categorie_id'])) {
            $q->where('etablissements.categorie_id', $f['categorie_id']);
        }

        return $q;
    }
}
