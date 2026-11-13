<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Models\Etablissement;
use App\Models\Ville;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * F20 : export d'une ville pour le mode hors ligne.
 * GET /villes/{ville}/export?page=1&par_page=400&version=<v>
 *  - `version` identique à la version courante : { a_jour: true } (rien à retélécharger) ;
 *  - sinon page de fiches publiées (JSON compact, sans valeurs nulles), gzip si le client l'accepte, ETag = version.
 * Contenu : fiches publiées uniquement, sans photos Premium ni donnée personnelle (annuaire public).
 */
class VilleExportController extends Controller
{
    public const PAR_PAGE_DEFAUT = 400;

    public function __invoke(Request $request, Ville $ville): Response
    {
        abort_unless($ville->actif, 404);
        $f = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'par_page' => ['nullable', 'integer', 'min:50', 'max:1000'],
            'version' => ['nullable', 'string', 'max:64'],
        ]);

        $version = self::version($ville);
        $etag = '"'.$version.'"';

        if (($f['version'] ?? null) === $version || $request->header('If-None-Match') === $etag) {
            return response()->json(['a_jour' => true, 'version' => $version], 200, ['ETag' => $etag]);
        }

        $page = (int) ($f['page'] ?? 1);
        $parPage = (int) ($f['par_page'] ?? self::PAR_PAGE_DEFAUT);
        $base = Etablissement::query()->publie()->dansVille($ville->id);
        $total = (clone $base)->count();

        $lignes = (clone $base)
            ->with([
                'categorie:id,code,libelle', 'commune:id,ville_id,nom', 'commune.ville:id,nom',
                'services:id,etablissement_id,service', 'assurances',
                'gardes' => fn ($q) => $q->where('date_fin', '>=', today()->toDateString())->orderBy('date_debut')->limit(3),
            ])
            ->orderBy('etablissements.id')
            ->forPage($page, $parPage)
            ->get()
            ->map(function (Etablissement $e) use ($ville) {
                $tableau = (new EtablissementResource($e))->resolve();
                $tableau['ville_id'] = $ville->id;
                $tableau['commune_id'] = $e->commune_id;

                return self::compacter($tableau);
            })
            ->values();

        $json = json_encode([
            'data' => $lignes,
            'meta' => [
                'version' => $version,
                'ville' => ['id' => $ville->id, 'nom' => $ville->nom],
                'total' => $total,
                'page' => $page,
                'pages' => max(1, (int) ceil($total / $parPage)),
                'genere_at' => now()->toIso8601String(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $entetes = ['Content-Type' => 'application/json; charset=utf-8', 'ETag' => $etag, 'Vary' => 'Accept-Encoding', 'Cache-Control' => 'private, max-age=0'];
        if (str_contains((string) $request->header('Accept-Encoding'), 'gzip') && function_exists('gzencode')) {
            $entetes['Content-Encoding'] = 'gzip';

            return response(gzencode($json, 6), 200, $entetes);
        }

        return response($json, 200, $entetes);
    }

    /**
     * Version = empreinte de ce qui peut changer dans l'export : fiches (dernière modification et nombre),
     * services, assurances et gardes à venir. Elle ne change donc que si les données changent.
     */
    public static function version(Ville $ville): string
    {
        $base = Etablissement::query()->publie()->dansVille($ville->id);
        $fiches = (clone $base)->selectRaw('COUNT(*) AS n, MAX(etablissements.updated_at) AS m')->first();
        $ids = (clone $base)->select('etablissements.id');

        $services = DB::table('etab_services')->whereIn('etablissement_id', $ids)->selectRaw('COUNT(*) AS n, COALESCE(MAX(id), 0) AS m')->first();
        $assurances = DB::table('etab_assurances')->whereIn('etablissement_id', $ids)->count();
        $gardes = DB::table('gardes')->whereIn('etablissement_id', $ids)->where('date_fin', '>=', today()->toDateString())
            ->selectRaw('COUNT(*) AS n, COALESCE(MAX(id), 0) AS m')->first();

        return substr(sha1(implode('|', [
            $fiches->n, $fiches->m, $services->n, $services->m, $assurances, $gardes->n, $gardes->m, today()->toDateString(),
        ])), 0, 16);
    }

    /** Retire les valeurs nulles et les listes vides (taille de l'export). */
    private static function compacter(array $a): array
    {
        $sortie = [];
        foreach ($a as $cle => $valeur) {
            if (is_array($valeur) && $valeur !== [] && array_is_list($valeur) === false) {
                $valeur = self::compacter($valeur);
            }
            if ($valeur === null || $valeur === [] || $valeur === '') {
                continue;
            }
            $sortie[$cle] = $valeur;
        }

        return $sortie;
    }
}
