<?php

namespace App\Jobs;

use App\Models\AlerteAudio;
use App\Services\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Push « nouveau message vocal » à tous les complices destinataires de l'alerte. */
class NotifierNouvelAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $audioId) {}

    public function handle(Notifier $notifier): void
    {
        $audio = AlerteAudio::with('alerte.user')->find($this->audioId);
        if (! $audio || ! $audio->alerte->estActive()) {
            return;
        }

        $alerte = $audio->alerte;
        foreach ($alerte->destinataires()->with('destinataireUser')->get() as $destinataire) {
            $notifier->push(
                $destinataire->destinataireUser,
                'BRAVIDO : nouveau message vocal',
                "{$alerte->user->nomAffiche()} a enregistré un message vocal.",
                ['type' => 'alerte_audio', 'alerte_id' => $alerte->id, 'audio_id' => $audio->id]
            );
        }
    }
}
