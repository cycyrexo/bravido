<?php

use App\Http\Controllers\Api\AlerteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComplicesController;
use App\Http\Controllers\Api\EtablissementController;
use App\Http\Controllers\Api\FavoriController;
use App\Http\Controllers\Api\FicheController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MessageSupportController;
use App\Http\Controllers\Api\PubliciteController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Middleware\EnsureCompteActif;
use Illuminate\Support\Facades\Route;

/*
| API mobile BRAVIDO — préfixe /api/v1 (voir bootstrap/app.php)
*/

// ---- Public (visiteur sans compte) ----
Route::get('config', [ReferentielController::class, 'config']);
Route::get('publicites', [PubliciteController::class, 'index']);
Route::post('publicites/stats', [PubliciteController::class, 'stats']);
Route::get('pharmacies', [EtablissementController::class, 'pharmacies']);
Route::get('pharmacies/garde', [EtablissementController::class, 'gardes']);
Route::get('etablissements', [EtablissementController::class, 'index']);
Route::get('etablissements/{etablissement}', [EtablissementController::class, 'show'])->whereNumber('etablissement');

// Envoi d'OTP : coût SMS, limite stricte. Vérification : 5 essais par code côté service.
Route::post('auth/otp', [AuthController::class, 'demanderOtp'])->middleware('throttle:otp');
Route::post('auth/verifier', [AuthController::class, 'verifierOtp'])->middleware('throttle:20,1');

// ---- Utilisateur connecté ----
Route::middleware(['auth:sanctum', EnsureCompteActif::class])->group(function () {
    Route::post('auth/deconnexion', [AuthController::class, 'deconnexion']);

    Route::get('me', [MeController::class, 'show']);
    Route::put('me', [MeController::class, 'update']);
    Route::delete('me', [MeController::class, 'destroy']);
    Route::post('me/device-token', [MeController::class, 'deviceToken']);

    // Cercle de complices
    Route::get('complices', [ComplicesController::class, 'index']);
    Route::post('complices', [ComplicesController::class, 'store']);
    Route::patch('complices/{complice}', [ComplicesController::class, 'update']);
    Route::delete('complices/{complice}', [ComplicesController::class, 'destroy']);
    Route::get('invitations', [ComplicesController::class, 'invitations']);
    Route::post('invitations/{complice}/accepter', [ComplicesController::class, 'accepter']);
    Route::post('invitations/{complice}/refuser', [ComplicesController::class, 'refuser']);

    // Alertes
    Route::post('alertes', [AlerteController::class, 'store'])->middleware('throttle:alerte');
    Route::get('alertes', [AlerteController::class, 'index']);
    Route::get('alertes-recues', [AlerteController::class, 'recues']);
    Route::get('alertes/{alerte}', [AlerteController::class, 'show']);
    Route::post('alertes/{alerte}/positions', [AlerteController::class, 'position']);
    Route::post('alertes/{alerte}/cloturer', [AlerteController::class, 'cloturer']);
    Route::post('alertes/{alerte}/annuler', [AlerteController::class, 'annuler']);
    Route::post('alertes/{alerte}/accuse', [AlerteController::class, 'accuse']);
    Route::post('alertes/{alerte}/vu', [AlerteController::class, 'vu']);
    Route::post('alertes/{alerte}/reponse', [AlerteController::class, 'reponse']);

    // Support et fiches établissement
    Route::get('messages', [MessageSupportController::class, 'index']);
    Route::post('messages', [MessageSupportController::class, 'store']);
    Route::get('messages/{message}', [MessageSupportController::class, 'show']);
    Route::get('fiches', [FicheController::class, 'index']);
    Route::post('fiches', [FicheController::class, 'store']);

    // Favoris
    Route::get('favoris', [FavoriController::class, 'index']);
    Route::post('favoris', [FavoriController::class, 'store']);
    Route::delete('favoris/{etablissement}', [FavoriController::class, 'destroy']);
});
