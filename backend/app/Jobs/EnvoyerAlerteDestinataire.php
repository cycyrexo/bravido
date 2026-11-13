<?php

namespace App\Jobs;

use App\Models\AlerteDestinataire;
use App\Models\AlerteEvenement;
use App\Services\AlerteService;
use App\Services\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Push immédiat ; si le complice n'a pas l'application (ou si le push échoue),
 * SMS de secours tout de suite. Sinon, vérification de l'accusé de réception après N secondes.
 */
class EnvoyerAlerteDestinataire implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [2, 5];

    public function __construct(public readonly int $destinataireId) {}

    public function handle(Notifier $notifier, AlerteService $alertes): void
    {
        $destinataire = AlerteDestinataire::with(['alerte.user', 'destinataireUser'])->find($this->destinataireId);
        if (! $destinataire || ! $destinataire->alerte->estActive()) {
            return;
        }

        $alerte = $destinataire->alerte;
        $pushOk = $notifier->push(
            $destinataire->destinataireUser,
            "ALERTE : {$alerte->user->nomAffiche()} a besoin d'aide",
            $alerte->libelleMotif().' — touchez pour voir sa position.',
            ['type' => 'alerte', 'alerte_id' => $alerte->id]
        );

        if ($pushOk) {
            $destinataire->forceFill(['push_envoye_at' => now()])->save();
            AlerteEvenement::noter($alerte->id, 'push_envoye', $destinataire, 'push');
            VerifierReceptionAlerte::dispatch($destinataire->id)
                ->onQueue('alertes')
                ->delay(now()->addSeconds(config('bravido.alerte.delai_sms_secours_s')));

            return;
        }

        $alertes->envoyerSmsSecours($destinataire);
    }
}
