<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alerte;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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
}
