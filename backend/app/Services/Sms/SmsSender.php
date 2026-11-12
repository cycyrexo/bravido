<?php

namespace App\Services\Sms;

interface SmsSender
{
    /** Retourne true si l'agrégateur a accepté le message. */
    public function envoyer(string $telephone, string $message): bool;
}
