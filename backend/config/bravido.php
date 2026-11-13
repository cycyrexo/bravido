<?php

return [

    // Numéro vert du call center (USR-03)
    'call_center' => env('BRAVIDO_CALL_CENTER', ''),

    // URG-01 : 7 complices maximum
    'complices_max' => 7,

    'alerte' => [
        // URG-03 : position rafraîchie toutes les 30 s pendant 1 h
        'duree_minutes' => 60,
        'intervalle_position_s' => 30,
        // SMS de secours si le push n'est pas accusé dans ce délai
        'delai_sms_secours_s' => (int) env('ALERTE_DELAI_SMS_S', 20),
        // Exigence sécurité : positions effacées après 30 jours
        'retention_jours' => 30,
        'retention_audit_mois' => 12,
    ],

    // « Je rentre chez moi » : trajet surveillé
    'trajet' => [
        'duree_min_minutes' => 15,
        'duree_max_minutes' => 180,
        // Délai de grâce après l'échéance avant le déclenchement automatique de l'alerte
        'grace_minutes' => 2,
        'prolongation_minutes' => 15,
        'prolongations_max' => 2,
        'intervalle_position_s' => 60,
    ],

    'otp' => [
        'longueur' => 6,
        'validite_minutes' => 5,
        'tentatives_max' => 5,
        'intervalle_s' => 60,
        'max_par_heure' => 5,
        // Code fixe pour le développement : ignoré hors APP_ENV=local
        'code_test' => env('OTP_CODE_TEST'),
    ],

    // HOP-02 : services recherchables
    'services_etablissement' => [
        'Urgences 24 h/24',
        'Maternité',
        'Pédiatrie',
        'Médecine générale',
        'Chirurgie',
        'Gynécologie',
        'Cardiologie',
        'Ophtalmologie',
        'Dentaire',
        'Radiologie',
        'Laboratoire',
        'Réanimation',
    ],
];
