<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\AlerteEvenement;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Maquette « Journal des alertes » : suivi technique de la remise aux complices.
 * Volontairement sans identité, position ni contenu : ils restent privés entre l'utilisateur et ses complices.
 */
class AlerteController extends Controller
{
    public function index(Request $request): View
    {
        $statut = $request->validate(['statut' => ['nullable', 'in:active,close,annulee']])['statut'] ?? null;

        $alertes = Alerte::query()
            ->select(['id', 'motif', 'statut', 'started_at', 'expires_at', 'closed_at'])
            ->withCount([
                'destinataires',
                'destinataires as recues_count' => fn ($q) => $q->whereNotNull('recu_at'),
                'destinataires as vues_count' => fn ($q) => $q->whereNotNull('vu_at'),
                'destinataires as sms_count' => fn ($q) => $q->whereNotNull('sms_envoye_at'),
                'destinataires as reponses_count' => fn ($q) => $q->whereNotNull('reponse'),
            ])
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->latest('started_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.alertes.index', compact('alertes', 'statut'));
    }

    /** F22 : détail d'une alerte (chronologie d'audit + statistiques de remise). Aucune identité, position ni contenu. */
    public function show(Alerte $alerte): View
    {
        $evenements = $alerte->evenements()->orderBy('created_at')->orderBy('id')->get();
        $alerte->loadCount([
            'destinataires',
            'destinataires as recues_count' => fn ($q) => $q->whereNotNull('recu_at'),
            'destinataires as vues_count' => fn ($q) => $q->whereNotNull('vu_at'),
            'destinataires as reponses_count' => fn ($q) => $q->whereNotNull('reponse'),
        ]);

        $stats = self::statistiques($alerte, $evenements);

        return view('admin.alertes.show', compact('alerte', 'evenements', 'stats'));
    }

    /** Délais (en secondes) depuis le déclenchement jusqu'au premier push / à la première ouverture, et recours au SMS. */
    public static function statistiques(Alerte $alerte, $evenements): array
    {
        $debut = $alerte->started_at;
        $delai = function (string $type) use ($evenements, $debut) {
            $premier = $evenements->firstWhere('type', $type);

            return $premier ? max(0, $debut->diffInSeconds($premier->created_at, false)) : null;
        };

        return [
            'premier_push_s' => $delai('push_envoye'),
            'premiere_vue_s' => $delai('vue'),
            'premiere_reponse_s' => $delai('reponse'),
            'sms_utilise' => $evenements->contains('type', 'sms_envoye'),
            'sms_nombre' => $evenements->where('type', 'sms_envoye')->count(),
            'push_nombre' => $evenements->where('type', 'push_envoye')->count(),
        ];
    }

    /** Export CSV du journal d'audit (administrateur uniquement). Colonnes sans donnée personnelle. */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
        ]);

        $requete = AlerteEvenement::query()
            ->join('alertes', 'alertes.id', '=', 'alerte_evenements.alerte_id')
            ->select(['alerte_evenements.alerte_id', 'alerte_evenements.type', 'alerte_evenements.destinataire_ref', 'alerte_evenements.canal', 'alerte_evenements.created_at', 'alertes.motif'])
            ->when($data['du'] ?? null, fn ($q, $v) => $q->where('alerte_evenements.created_at', '>=', $v))
            ->when($data['au'] ?? null, fn ($q, $v) => $q->where('alerte_evenements.created_at', '<=', $v.' 23:59:59'))
            ->orderBy('alerte_evenements.alerte_id')->orderBy('alerte_evenements.id');

        $nom = 'journal-alertes-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($requete) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
            fputcsv($sortie, ['alerte', 'motif', 'evenement', 'destinataire_rang', 'canal', 'date_heure'], ';');
            $requete->lazyById(1000, 'alerte_evenements.id', 'id')->each(function ($e) use ($sortie) {
                fputcsv($sortie, [$e->alerte_id, $e->motif, $e->type, $e->destinataire_ref, $e->canal, $e->created_at->format('Y-m-d H:i:s')], ';');
            });
            fclose($sortie);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
