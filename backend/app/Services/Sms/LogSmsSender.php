<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Développement : le SMS est écrit dans storage/logs/laravel.log. */
class LogSmsSender implements SmsSender
{
    public function envoyer(string $telephone, string $message): bool
    {
        Log::info("[SMS] {$telephone} : {$message}");

        return true;
    }
}
