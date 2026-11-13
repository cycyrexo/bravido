<?php

return [

    'sms' => [
        // log : écrit le SMS dans storage/logs (développement)
        // http : envoie via l'agrégateur SMS (adapter HttpSmsSender au contrat de l'agrégateur retenu)
        'driver' => env('SMS_DRIVER', 'log'),
        'url' => env('SMS_URL'),
        'token' => env('SMS_TOKEN'),
        'sender' => env('SMS_SENDER', 'BRAVIDO'),
    ],

    // OCR des programmes de garde (images) : chemin de tesseract(.exe), détecté automatiquement si vide
    'tesseract' => [
        'path' => env('TESSERACT_PATH'),
    ],

    // F19 : paiement mobile. Le bac à sable simule un paiement local (désactivé en production).
    // Orange Money, MTN MoMo et Wave : adaptateurs vides tant que les comptes marchands ne sont pas obtenus.
    'paiement' => [
        'sandbox' => (bool) env('PAIEMENT_SANDBOX', env('APP_ENV', 'production') !== 'production'),
        'sandbox_secret' => env('PAIEMENT_SANDBOX_SECRET'),
        'orange_money' => [
            'client_id' => env('ORANGE_MONEY_CLIENT_ID'),
            'client_secret' => env('ORANGE_MONEY_CLIENT_SECRET'),
            'merchant_key' => env('ORANGE_MONEY_MERCHANT_KEY'),
            'webhook_secret' => env('ORANGE_MONEY_WEBHOOK_SECRET'),
        ],
        'mtn_momo' => [
            'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
            'api_user' => env('MTN_MOMO_API_USER'),
            'api_key' => env('MTN_MOMO_API_KEY'),
            'webhook_secret' => env('MTN_MOMO_WEBHOOK_SECRET'),
        ],
        'wave' => [
            'api_key' => env('WAVE_API_KEY'),
            'webhook_secret' => env('WAVE_WEBHOOK_SECRET'),
        ],
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        // Chemin absolu du fichier JSON du compte de service Firebase
        'credentials' => env('FCM_CREDENTIALS'),
        // Bundle CA pour les appels HTTPS vers Google (utile si le php.ini n'a pas de curl.cainfo, ex. WAMP)
        'ca_bundle' => env('FCM_CA_BUNDLE', is_file(storage_path('certs/cacert.pem')) ? storage_path('certs/cacert.pem') : null),
    ],

];
