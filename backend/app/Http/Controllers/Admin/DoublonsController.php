<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\Ville;
use App\Services\Doublons;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Doublons probables et brouillons orphelins « (nom à compléter — page N) ». */
class DoublonsController extends Controller
{
    private const PAR_PAGE = 10;

    public function index(Request $request, Doublons $service): View
    {
        $f = $request->validate([
            'ville_id' => ['nullable', 'integer'],
            'motif' => ['nullable', 'in:nom,tel'],
            'meme_commune' => ['nullable', 'boolean'],
        ]);

        $groupes = collect($service->groupes($f['ville_id'] ?? null))
            ->when(! empty($f['motif']), fn ($c) => $c->filter(fn ($g) => in_array($f['motif'], $g['motifs'], true)))
            ->when(! empty($f['meme_commune']), fn ($c) => $c->filter(fn ($g) => $g['meme_commune']))
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $pageGroupes = $groupes->slice(($page - 1) * self::PAR_PAGE, self::PAR_PAGE)->values();
        $pagination = new LengthAwarePaginator($pageGroupes, $groupes->count(), self::PAR_PAGE, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        $fiches = $this->charger($pageGroupes->pluck('ids')->flatten()->all());
        $presentation = $pageGroupes->map(function (array $g) use ($fiches) {
            $membres = collect($g['ids'])->map(fn ($id) => $fiches[$id])->values();
            $meilleur = $membres->sortByDesc(fn ($e) => Doublons::score($e))->first();

            return $g + ['fiches' => $membres, 'lignes' => $this->comparer($membres), 'conseille' => $meilleur->id];
        });

        return view('admin.doublons.index', [
            'groupes' => $presentation, 'pagination' => $pagination, 'total' => $groupes->count(),
            'f' => $f, 'villes' => Ville::orderBy('nom')->get(),
        ]);
    }

    public function orphelins(Request $request): View
    {
        $f = $request->validate(['ville_id' => ['nullable', 'integer']]);

        $orphelins = Etablissement::query()
            ->where('nom', 'like', '(nom %')
            ->when(! empty($f['ville_id']), fn ($q) => $q->dansVille((int) $f['ville_id']))
            ->with(['categorie', 'commune.ville', 'services'])
            ->get()
            ->filter(fn (Etablissement $e) => Doublons::estOrphelin($e->nom))
            ->sortBy(fn (Etablissement $e) => [Doublons::pagePdf($e) ?? 9999, $e->id])
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $pagination = new LengthAwarePaginator($orphelins->slice(($page - 1) * 25, 25)->values(), $orphelins->count(), 25, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('admin.doublons.orphelins', [
            'orphelins' => $pagination, 'f' => $f, 'villes' => Ville::orderBy('nom')->get(),
        ]);
    }

    /** Recherche de la fiche cible (JSON) : même commune, autres que les orphelins ; hôpitaux et cliniques d'abord. */
    public function cibles(Request $request): JsonResponse
    {
        $d = $request->validate([
            'commune_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $q = Etablissement::query()
            ->join('categories', 'categories.id', '=', 'etablissements.categorie_id')
            ->where('etablissements.commune_id', $d['commune_id'])
            ->where('etablissements.nom', 'not like', '(nom %')
            ->when(! empty($d['q']), fn ($r) => $r->where('etablissements.nom', 'like', '%'.addcslashes($d['q'], '%_\\').'%'))
            ->orderByRaw("categories.code = 'pharmacie'")  // les pharmacies en dernier
            ->orderBy('etablissements.nom')
            ->limit(15)
            ->get(['etablissements.id', 'etablissements.nom', 'categories.libelle as categorie', 'etablissements.statut']);

        return response()->json($q->map(fn ($e) => [
            'id' => $e->id, 'nom' => $e->nom, 'categorie' => $e->categorie, 'statut' => $e->statut,
        ]));
    }

    public function ignorer(Request $request, Doublons $service): RedirectResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:2'], 'ids.*' => ['integer', 'exists:etablissements,id']]);

        $n = $service->ignorer($d['ids'], $request->user()->id);
        Journal::log('doublons.ignorer', null, null, ['etablissements' => array_map('intval', $d['ids']), 'paires' => $n]);

        return back()->with('succes', 'Notés « pas un doublon » : ces fiches ne seront plus proposées ensemble.');
    }

    public function fusionner(Request $request, Doublons $service): RedirectResponse
    {
        $d = $request->validate([
            'maitre_id' => ['required', 'integer', 'exists:etablissements,id'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $r = $service->fusionner((int) $d['maitre_id'], $d['ids']);
        Journal::log('etablissements.fusion', $r['maitre'], $r['avant'], $r['apres']);

        $nb = count($r['avant']['supprimes']);

        return back()->with('succes', "{$nb} fiche(s) fusionnée(s) dans « {$r['maitre']->nom} ».");
    }

    /** @param  list<int>  $ids */
    private function charger(array $ids): Collection
    {
        $favoris = DB::table('favoris')->whereIn('etablissement_id', $ids)
            ->selectRaw('etablissement_id, count(*) as n')->groupBy('etablissement_id')->pluck('n', 'etablissement_id');

        return Etablissement::whereIn('id', $ids)
            ->with(['categorie', 'commune.ville', 'services', 'assurances'])
            ->withCount('gardes')
            ->get()->each(fn ($e) => $e->favoris_count = (int) ($favoris[$e->id] ?? 0))
            ->keyBy('id');
    }

    /** Matrice de comparaison : une ligne par champ, avec drapeau « diffère » pour la mise en évidence. */
    private function comparer(Collection $fiches): array
    {
        $champs = [
            'Nom' => fn ($e) => $e->nom,
            'Catégorie' => fn ($e) => $e->categorie->libelle ?? '',
            'Commune' => fn ($e) => $e->commune->nom,
            'Adresse' => fn ($e) => $e->adresse,
            'Repère' => fn ($e) => $e->repere,
            'Téléphone 1' => fn ($e) => $e->tel1,
            'Téléphone 2' => fn ($e) => $e->tel2,
            'Autres numéros' => fn ($e) => $e->tel_autres,
            'E-mail' => fn ($e) => $e->email,
            'Site web' => fn ($e) => $e->site_web,
            'Responsable' => fn ($e) => $e->responsable_nom,
            'Horaires' => fn ($e) => $e->h24 ? '24 h/24' : $e->horaires,
            'Description' => fn ($e) => $e->description,
            'Statut' => fn ($e) => $e->statut === 'publie' ? 'Publié' : 'Brouillon',
            'Position' => fn ($e) => $e->position_precise ? 'GPS vérifié ('.round($e->lat, 5).', '.round($e->lng, 5).')' : 'Approximative (centre de commune)',
            'Services' => fn ($e) => $e->services->pluck('service')->implode(', '),
            'Assurances' => fn ($e) => $e->assurances->pluck('nom')->implode(', '),
            'Gardes' => fn ($e) => (string) $e->gardes_count,
            'Favoris' => fn ($e) => (string) $e->favoris_count,
            'Origine' => fn ($e) => $e->source_ref ? preg_replace('/:[0-9a-f]{20}$/', '', $e->source_ref) : '',
        ];

        $lignes = [];
        foreach ($champs as $libelle => $valeur) {
            $valeurs = $fiches->map(fn ($e) => (string) $valeur($e))->all();
            $norm = array_unique(array_map(fn ($v) => mb_strtolower(trim($v)), $valeurs));
            $lignes[] = ['libelle' => $libelle, 'valeurs' => $valeurs, 'diff' => count($norm) > 1];
        }

        return $lignes;
    }
}
