<?php

namespace App\Services\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Firebase Cloud Messaging, API HTTP v1. */
class FcmSender
{
    public const OK = 'ok';

    public const TOKEN_INVALIDE = 'token_invalide';

    public const ERREUR = 'erreur';

    public const NON_CONFIGURE = 'non_configure';

    public function envoyer(string $token, string $titre, string $corps, array $data = []): string
    {
        $projet = config('services.fcm.project_id');
        $credentials = config('services.fcm.credentials');

        if (! $projet || ! $credentials || ! is_file($credentials)) {
            Log::info("[PUSH non configuré] {$titre} : {$corps}", $data);

            return self::NON_CONFIGURE;
        }

        try {
            $response = Http::withToken($this->accessToken($credentials))
                ->withOptions(['verify' => $this->verify()])
                ->timeout(5)
                ->post("https://fcm.googleapis.com/v1/projects/{$projet}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $titre, 'body' => $corps],
                        // FCM n'accepte que des chaînes dans data
                        'data' => array_map('strval', $data),
                        // Canaux créés par l'app (MainActivity) : "alertes" = importance max + son d'alarme
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => ($data['type'] ?? null) === 'alerte' ? 'alertes' : 'bravido',
                            ],
                        ],
                        'apns' => [
                            'headers' => ['apns-priority' => '10'],
                            'payload' => ['aps' => ['sound' => 'default']],
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::error('Erreur FCM', ['message' => $e->getMessage()]);

            return self::ERREUR;
        }

        if ($response->successful()) {
            return self::OK;
        }

        $statut = $response->json('error.status');
        if ($response->status() === 404 || in_array($statut, ['NOT_FOUND', 'UNREGISTERED'], true)) {
            return self::TOKEN_INVALIDE;
        }

        Log::warning('Échec FCM', ['status' => $response->status(), 'body' => $response->body()]);

        return self::ERREUR;
    }

    private function accessToken(string $credentials): string
    {
        // Le jeton OAuth Google vaut 1 h : on le garde 50 min
        return Cache::remember('fcm_access_token', 3000, function () use ($credentials) {
            $creds = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                $credentials
            );

            $handler = HttpHandlerFactory::build(new Client(['verify' => $this->verify(), 'timeout' => 10]));

            return $creds->fetchAuthToken($handler)['access_token'];
        });
    }

    /** Chemin du bundle CA configuré, sinon vérification TLS par défaut. */
    private function verify(): string|bool
    {
        $bundle = config('services.fcm.ca_bundle');

        return $bundle && is_file($bundle) ? $bundle : true;
    }
}
