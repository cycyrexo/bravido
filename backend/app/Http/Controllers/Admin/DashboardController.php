<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\Etablissement;
use App\Models\Fiche;
use App\Models\Garde;
use App\Models\MessageSupport;
use App\Models\Ville;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/** BO-01 — maquette « Back-office : tableau de bord ». */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $villesActives = Ville::where('actif', true)->orderBy('nom')->get();
        $villesSansGarde = $villesActives->filter(fn (Ville $ville) => ! Garde::enCours()
            ->whereHas('etablissement', fn ($q) => $q->publie()->dansVille($ville->id))
            ->exists());

        $gardes = Garde::enCours()->whereHas('etablissement', fn ($q) => $q->publie());
        $periode = (clone $gardes)->selectRaw('MIN(date_debut) AS du, MAX(date_fin) AS au')->first();

        $stats = DB::table('pub_stats')->selectRaw('COALESCE(SUM(impressions), 0) AS i, COALESCE(SUM(clics), 0) AS c')->first();
        $tauxClic = $stats->i > 0 ? number_format(100 * $stats->c / $stats->i, 1, ',', ' ').' %' : '—';

        $fichesAttente = Fiche::where('statut', 'en_attente')->count();
        $messagesNonTraites = MessageSupport::where('statut', '!=', 'traite')->count();
        $alertesActives = Alerte::where('statut', 'active')->where('expires_at', '>', now())->count();

        return view('admin.dashboard', [
            'villes' => $villesActives->pluck('nom')->implode(', '),
            'cartes' => [
                ['valeur' => Etablissement::where('statut', 'publie')->count(), 'libelle' => 'établissements publiés', 'lien' => route('admin.etablissements.index', ['statut' => 'publie'])],
                [
                    'valeur' => $gardes->count(),
                    'libelle' => 'pharmacies de garde'.($periode?->du ? ' ('.date('d/m', strtotime($periode->du)).'–'.date('d/m', strtotime($periode->au)).')' : ''),
                    'lien' => route('admin.gardes.index'),
                ],
                ['valeur' => $fichesAttente, 'libelle' => 'fiches à valider', 'couleur' => $fichesAttente ? 'orange' : '', 'lien' => route('admin.fiches.index')],
                ['valeur' => $messagesNonTraites, 'libelle' => 'messages non traités', 'couleur' => $messagesNonTraites ? 'orange' : '', 'lien' => route('admin.messages.index')],
                ['valeur' => $alertesActives, 'libelle' => 'alertes actives', 'couleur' => $alertesActives ? 'rouge' : '', 'lien' => route('admin.alertes.index')],
                ['valeur' => $tauxClic, 'libelle' => 'taux de clic publicité', 'lien' => auth()->user()->isAdmin() ? route('admin.publicites.index') : '#'],
            ],
            'villesSansGarde' => $villesSansGarde,
            'fiches' => Fiche::with('auteur')->where('statut', 'en_attente')->latest()->limit(5)->get(),
            'messages' => MessageSupport::with('user')->where('statut', 'nouveau')->latest()->limit(5)->get(),
        ]);
    }
}
