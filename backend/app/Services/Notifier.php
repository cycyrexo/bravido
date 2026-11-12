<?php

namespace App\Services;

use App\Models\User;
use App\Services\Push\FcmSender;

/** Envoie un push sur tous les téléphones d'un utilisateur. */
class Notifier
{
    public function __construct(private readonly FcmSender $fcm) {}

    /** Retourne true si au moins un téléphone a accepté le push. */
    public function push(?User $user, string $titre, string $corps, array $data = []): bool
    {
        if (! $user) {
            return false;
        }

        $ok = false;
        foreach ($user->deviceTokens()->get() as $device) {
            $resultat = $this->fcm->envoyer($device->token, $titre, $corps, $data);

            if ($resultat === FcmSender::OK) {
                $ok = true;
                $device->forceFill(['last_used_at' => now()])->save();
            } elseif ($resultat === FcmSender::TOKEN_INVALIDE) {
                $device->delete();
            }
        }

        return $ok;
    }
}
