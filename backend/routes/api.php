<?php

use App\Http\Controllers\Api\AlerteAudioController;
use App\Http\Controllers\Api\AlerteController;
use App\Http\Controllers\Api\SanteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RechercheController;
use App\Http\Controllers\Api\ComplicesController;
use App\Http\Controllers\Api\EtablissementController;
use App\Http\Controllers\Api\EvenementController;
use App\Http\Controllers\Api\FavoriController;
use App\Http\Controllers\Api\FicheController;
use App\Http\Controllers\Api\FicheMedicaleController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MessageSupportController;
use App\Http\Controllers\Api\MonEtablissementController;
use App\Http\Controllers\Api\PositionProposeeController;
use App\Http\Controllers\Api\PubliciteController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\TrajetController;
use App\Http\Middleware\EnsureCompteActif;
use Illuminate\Support\Facades\Route;

/*
| API mobile BRAVIDO — préfixe /api/v1 (voir bootstrap/app.php)
*/

// ---- Public (visiteur sans compte) ----
Route::get('config', [ReferentielController::class, 'config']);
Route::get('sante', SanteController::class)->middleware('throttle:60,1');
// F20 : export d'une ville pour le mode hors ligne (paginé, gzip, version/ETag)
Route::get('villes/{ville}/export', \App\Http\Controllers\Api\VilleExportController::class)
    ->whereNumber('ville')->middleware('throttle:30,1');
Route::get('publicites', [PubliciteController::class, 'index']);
Route::post('publicites/stats', [PubliciteController::class, 'stats']);
Route::get('pharmacies', [EtablissementController::class, 'pharmacies']);
Route::get('pharmacies/garde', [EtablissementController::class, 'gardes']);
Route::get('etablissements', [EtablissementController::class, 'index']);
Route::get('recherche', [RechercheController::class, 'index'])->middleware('throttle:60,1');
Route::get('recherche/suggestions', [RechercheController::class, 'suggestions'])->middleware('throttle:90,1');
Route::get('etablissements/carte', [EtablissementController::class, 'carte'])->middleware('throttle:60,1');
Route::get('etablissements/{etablissement}', [EtablissementController::class, 'show'])->whereNumber('etablissement');
// F19 : plans (public) et notification de paiement (signée, idempotente)
Route::get('plans', [\App\Http\Controllers\Api\AbonnementController::class, 'plans']);
Route::post('paiements/webhook/{fournisseur}', [\App\Http\Controllers\Api\AbonnementController::class, 'webhook'])
    ->where('fournisseur', '[a-z_]+')->middleware('throttle:60,1');
// F13 : avis publiés d'un établissement (le jeton, s'il est fourni, ajoute « mon avis »)
Route::get('etablissements/{etablissement}/avis', [\App\Http\Controllers\Api\AvisController::class, 'index'])
    ->whereNumber('etablissement')->middleware('throttle:60,1');
// F17 : compteurs anonymes (vue / appel / itinéraire), sans identifiant d'utilisateur
Route::post('etablissements/{etablissement}/evenements', [EvenementController::class, 'store'])
    ->whereNumber('etablissement')->middleware('throttle:120,1');

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
    Route::get('me/fiche-medicale', [FicheMedicaleController::class, 'show']);
    Route::put('me/fiche-medicale', [FicheMedicaleController::class, 'update']);

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
    Route::post('alertes/{alerte}/audio', [AlerteAudioController::class, 'store'])->middleware('throttle:10,1');
    Route::get('alertes/{alerte}/audio/{audio}', [AlerteAudioController::class, 'show']);

    // « Je rentre chez moi » : trajet surveillé
    Route::post('trajets', [TrajetController::class, 'store']);
    Route::get('trajets/en-cours', [TrajetController::class, 'enCours']);
    Route::get('trajets-suivis', [TrajetController::class, 'suivis']);
    Route::post('trajets/{trajet}/positions', [TrajetController::class, 'position'])->middleware('throttle:30,1');
    Route::post('trajets/{trajet}/arrive', [TrajetController::class, 'arrive']);
    Route::post('trajets/{trajet}/prolonger', [TrajetController::class, 'prolonger']);

    // Support et fiches établissement
    Route::get('messages', [MessageSupportController::class, 'index']);
    Route::post('messages', [MessageSupportController::class, 'store']);
    Route::get('messages/{message}', [MessageSupportController::class, 'show']);
    Route::get('fiches', [FicheController::class, 'index']);
    Route::post('fiches', [FicheController::class, 'store']);

    // F2 : « Je suis sur place » — position confirmée par l'utilisateur (modérée au back-office)
    Route::post('etablissements/{etablissement}/positions-proposees', [PositionProposeeController::class, 'store'])
        ->whereNumber('etablissement')->middleware('throttle:10,1');

    // F18 : espace du responsable d'établissement (réservé au responsable validé de l'établissement)
    Route::get('mon-etablissement', [MonEtablissementController::class, 'index']);
    Route::get('mon-etablissement/{etablissement}', [MonEtablissementController::class, 'show'])->whereNumber('etablissement');
    Route::post('mon-etablissement/{etablissement}/corrections', [MonEtablissementController::class, 'correction'])->whereNumber('etablissement')->middleware('throttle:20,1');
    Route::post('mon-etablissement/{etablissement}/garde', [MonEtablissementController::class, 'garde'])->whereNumber('etablissement')->middleware('throttle:20,1');
    Route::post('mon-etablissement/{etablissement}/photos', [MonEtablissementController::class, 'ajouterPhoto'])->whereNumber('etablissement')->middleware('throttle:20,1');
    Route::delete('mon-etablissement/{etablissement}/photos/{photo}', [MonEtablissementController::class, 'supprimerPhoto'])->whereNumber(['etablissement', 'photo']);
    Route::put('mon-etablissement/{etablissement}/horaires-detail', [MonEtablissementController::class, 'horairesDetail'])->whereNumber('etablissement');

    // F19 : abonnement famille (les alertes restent gratuites et identiques pour tous)
    Route::get('abonnement', [\App\Http\Controllers\Api\AbonnementController::class, 'show']);
    Route::post('abonnement/souscrire', [\App\Http\Controllers\Api\AbonnementController::class, 'souscrire'])->middleware('throttle:10,1');
    Route::post('abonnement/membres', [\App\Http\Controllers\Api\AbonnementController::class, 'inviter'])->middleware('throttle:20,1');
    Route::get('abonnement/invitations', [\App\Http\Controllers\Api\AbonnementController::class, 'invitations']);
    Route::post('abonnement/invitations/{membre}/accepter', [\App\Http\Controllers\Api\AbonnementController::class, 'accepter'])->whereNumber('membre');
    Route::delete('abonnement/membres/{membre}', [\App\Http\Controllers\Api\AbonnementController::class, 'retirer'])->whereNumber('membre');

    // F13 : avis et notes (pré-modération)
    Route::get('mes-avis', [\App\Http\Controllers\Api\AvisController::class, 'mesAvis']);
    Route::post('etablissements/{etablissement}/mon-avis', [\App\Http\Controllers\Api\AvisController::class, 'store'])->whereNumber('etablissement')->middleware('throttle:10,1');
    Route::put('etablissements/{etablissement}/mon-avis', [\App\Http\Controllers\Api\AvisController::class, 'update'])->whereNumber('etablissement')->middleware('throttle:10,1');
    Route::delete('etablissements/{etablissement}/mon-avis', [\App\Http\Controllers\Api\AvisController::class, 'destroy'])->whereNumber('etablissement');
    Route::post('avis/{avis}/signaler', [\App\Http\Controllers\Api\AvisController::class, 'signaler'])->whereNumber('avis')->middleware('throttle:20,1');
    Route::get('mon-etablissement/{etablissement}/avis', [\App\Http\Controllers\Api\AvisController::class, 'pourResponsable'])->whereNumber('etablissement');
    Route::post('mon-etablissement/avis/{avis}/reponse', [\App\Http\Controllers\Api\AvisController::class, 'repondre'])->whereNumber('avis')->middleware('throttle:20,1');

    // Favoris
    Route::get('favoris', [FavoriController::class, 'index']);
    Route::post('favoris', [FavoriController::class, 'store']);
    Route::delete('favoris/{etablissement}', [FavoriController::class, 'destroy']);
});
