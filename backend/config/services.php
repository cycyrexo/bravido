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

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        // Chemin absolu du fichier JSON du compte de service Firebase
        'credentials' => env('FCM_CREDENTIALS'),
        // Bundle CA pour les appels HTTPS vers Google (utile si le php.ini n'a pas de curl.cainfo, ex. WAMP)
        'ca_bundle' => env('FCM_CA_BUNDLE', is_file(storage_path('certs/cacert.pem')) ? storage_path('certs/cacert.pem') : null),
    ],

];
