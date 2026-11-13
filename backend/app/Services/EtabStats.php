<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\EtabStat;
use Illuminate\Support\Facades\DB;

/** Compteurs quotidiens par établissement (vues, appels, itinéraires). Aucun identifiant d'utilisateur n'est conservé. */
class EtabStats
{
    public const TYPES = ['vue' => 'vues', 'appel' => 'appels', 'itineraire' => 'itineraires'];

    public function enregistrer(Etablissement $etab, string $type): void
    {
        $colonne = self::TYPES[$type] ?? null;
        if ($colonne === null) {
            return;
        }
        // Incrément atomique (upsert sur la clé unique établissement + jour)
        DB::table('etab_stats')->upsert(
            [['etablissement_id' => $etab->id, 'jour' => today()->toDateString(), $colonne => 1]],
            ['etablissement_id', 'jour'],
            [$colonne => DB::raw("{$colonne} + 1")]
        );
    }

    /** @return array{vues: int, appels: int, itineraires: int, jours: int} */
    public function resume(int $etablissementId, int $jours = 30): array
    {
        $r = EtabStat::where('etablissement_id', $etablissementId)
            ->where('jour', '>', today()->subDays($jours)->toDateString())
            ->selectRaw('COALESCE(SUM(vues),0) as vues, COALESCE(SUM(appels),0) as appels, COALESCE(SUM(itineraires),0) as itineraires')
            ->first();

        return ['vues' => (int) $r->vues, 'appels' => (int) $r->appels, 'itineraires' => (int) $r->itineraires, 'jours' => $jours];
    }

    /** Résumés de plusieurs établissements en une requête : [id => [vues, appels, itineraires]]. */
    public function resumes(array $ids, int $jours = 30): array
    {
        return EtabStat::whereIn('etablissement_id', $ids)
            ->where('jour', '>', today()->subDays($jours)->toDateString())
            ->selectRaw('etablissement_id, SUM(vues) as vues, SUM(appels) as appels, SUM(itineraires) as itineraires')
            ->groupBy('etablissement_id')->get()
            ->mapWithKeys(fn ($r) => [$r->etablissement_id => ['vues' => (int) $r->vues, 'appels' => (int) $r->appels, 'itineraires' => (int) $r->itineraires]])
            ->all();
    }
}
