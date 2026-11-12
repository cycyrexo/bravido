<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Publicite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** ACC-02 : carrousel publicitaire (jamais affiché dans l'écran Alerte). */
class PubliciteController extends Controller
{
    /** GET /publicites?ville_id= */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['ville_id' => ['nullable', 'integer']]);

        $publicites = Publicite::diffusables($request->integer('ville_id') ?: null)
            ->orderBy('ordre')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $publicites->map(fn (Publicite $p) => [
                'id' => $p->id,
                'annonceur' => $p->annonceur,
                'titre' => $p->titre,
                'image_url' => $p->imageUrl(),
                'lien' => $p->lien,
            ]),
        ]);
    }

    /** POST /publicites/stats — envoi groupé des impressions et clics */
    public function stats(Request $request): Response
    {
        $data = $request->validate([
            'impressions' => ['nullable', 'array', 'max:100'],
            'impressions.*' => ['integer'],
            'clics' => ['nullable', 'array', 'max:20'],
            'clics.*' => ['integer'],
        ]);

        $impressions = array_count_values($data['impressions'] ?? []);
        $clics = array_count_values($data['clics'] ?? []);
        $existants = Publicite::whereIn('id', array_keys($impressions + $clics))->pluck('id')->all();

        foreach ($existants as $id) {
            $this->incrementer($id, (int) ($impressions[$id] ?? 0), (int) ($clics[$id] ?? 0));
        }

        return response()->noContent();
    }

    private function incrementer(int $id, int $impressions, int $clics): void
    {
        DB::table('pub_stats')->upsert(
            [['publicite_id' => $id, 'jour' => today()->toDateString(), 'impressions' => $impressions, 'clics' => $clics]],
            ['publicite_id', 'jour'],
            [
                'impressions' => DB::raw('impressions + '.$impressions),
                'clics' => DB::raw('clics + '.$clics),
            ]
        );
    }
}
