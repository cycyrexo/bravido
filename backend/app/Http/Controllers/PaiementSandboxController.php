<?php

namespace App\Http\Controllers;

use App\Models\Paiement;
use App\Services\Paiement\SandboxProvider;
use App\Services\PaiementService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** F19 : page de paiement SIMULÉE (« Simuler succès / échec »). Indisponible quand le bac à sable est désactivé. */
class PaiementSandboxController extends Controller
{
    public function __construct(private readonly PaiementService $paiements) {}

    public function page(string $reference): View
    {
        $p = $this->paiement($reference);

        return view('paiement.sandbox', ['paiement' => $p, 'termine' => $p->statut !== 'en_attente']);
    }

    public function simuler(Request $request, string $reference, string $issue): View
    {
        abort_unless(in_array($issue, ['succes', 'echec'], true), 404);
        $p = $this->paiement($reference);

        // Même chemin que le vrai webhook : corps JSON signé HMAC, vérifié puis traité de façon idempotente
        $corps = json_encode(['reference' => $p->reference, 'statut' => $issue === 'succes' ? 'reussi' : 'echoue', 'montant' => $p->montant, 'simule' => true]);
        $signature = (new SandboxProvider)->signer($corps);
        $this->paiements->traiterWebhook('sandbox', $corps, [SandboxProvider::EN_TETE => $signature]);

        return view('paiement.sandbox', ['paiement' => $p->fresh(), 'termine' => true]);
    }

    private function paiement(string $reference): Paiement
    {
        abort_unless((new SandboxProvider)->configure(), 404);

        return Paiement::where('reference', $reference)->where('fournisseur', 'sandbox')->with('plan')->firstOrFail();
    }
}
