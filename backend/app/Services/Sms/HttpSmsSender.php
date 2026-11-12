<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Passerelle SMS générique (JSON + jeton Bearer).
 * À ADAPTER au contrat de l'agrégateur retenu (Orange SMS API, MTN, agrégateur local...).
 */
class HttpSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $expediteur,
    ) {}

    public function envoyer(string $telephone, string $message): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(10)
                ->post($this->url, [
                    'from' => $this->expediteur,
                    'to' => $telephone,
                    'text' => $message,
                ]);

            if (! $response->successful()) {
                Log::warning('Échec envoi SMS', ['to' => $telephone, 'status' => $response->status(), 'body' => $response->body()]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::error('Erreur passerelle SMS', ['to' => $telephone, 'message' => $e->getMessage()]);

            return false;
        }
    }
}
