<?php

namespace App\Jobs;

use App\Models\AlerteDestinataire;
use App\Services\AlerteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Push non accusé dans le délai : SMS de secours (complice sans données mobiles). */
class VerifierReceptionAlerte implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $destinataireId) {}

    public function handle(AlerteService $alertes): void
    {
        $destinataire = AlerteDestinataire::with('alerte')->find($this->destinataireId);

        if (! $destinataire
            || ! $destinataire->alerte->estActive()
            || $destinataire->recu_at !== null
            || $destinataire->sms_envoye_at !== null) {
            return;
        }

        $alertes->envoyerSmsSecours($destinataire);
    }
}
