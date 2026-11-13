<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// ---- Pages publiques ouvertes depuis un SMS ----
Route::middleware('throttle:public-web')->group(function () {
    Route::get('a/{token}', [PublicController::class, 'carte'])->name('alerte.carte');
    Route::get('a/{token}/position', [PublicController::class, 'position'])->name('alerte.position');
    Route::get('invitation/{token}', [PublicController::class, 'invitation'])->name('invitation.show');
    Route::post('invitation/{token}', [PublicController::class, 'repondreInvitation'])->name('invitation.repondre');
});

// F19 : page de paiement simulée (bac à sable uniquement)
Route::middleware('throttle:public-web')->group(function () {
    Route::get('paiement/sandbox/{reference}', [\App\Http\Controllers\PaiementSandboxController::class, 'page'])->name('paiement.sandbox');
    Route::post('paiement/sandbox/{reference}/{issue}', [\App\Http\Controllers\PaiementSandboxController::class, 'simuler'])->name('paiement.sandbox.simuler');
});

// ---- Back-office ----
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [Admin\AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:admin-login');
    });

    Route::middleware(['auth', 'role:admin,moderateur'])->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'logout'])->name('logout');
        Route::get('/', Admin\DashboardController::class)->name('dashboard');

        // BO-02 Établissements
        Route::resource('etablissements', Admin\EtablissementController::class)->except(['show', 'destroy']);
        Route::post('etablissements/{etablissement}/statut', [Admin\EtablissementController::class, 'basculerStatut'])->name('etablissements.statut');
        Route::post('etablissements/{etablissement}/verifier', [Admin\EtablissementController::class, 'verifier'])->name('etablissements.verifier');

        // Assistant de géolocalisation (établissements importés sans GPS)
        Route::get('geolocalisation', [Admin\GeolocalisationController::class, 'index'])->name('geolocalisation.index');
        Route::get('geolocalisation/{etablissement}/candidats', [Admin\GeolocalisationController::class, 'candidats'])->name('geolocalisation.candidats');
        Route::post('geolocalisation/{etablissement}/valider', [Admin\GeolocalisationController::class, 'valider'])->name('geolocalisation.valider');
        Route::post('geolocalisation/{etablissement}/ignorer', [Admin\GeolocalisationController::class, 'ignorer'])->name('geolocalisation.ignorer');
        Route::post('geolocalisation/propositions/{etablissement}/valider', [Admin\GeolocalisationController::class, 'validerProposition'])->name('geolocalisation.propositions.valider');
        Route::post('geolocalisation/propositions/{etablissement}/rejeter', [Admin\GeolocalisationController::class, 'rejeterProposition'])->name('geolocalisation.propositions.rejeter');
        Route::post('geolocalisation/reinitialiser', [Admin\GeolocalisationController::class, 'reinitialiserIgnores'])->name('geolocalisation.reinitialiser');

        // Doublons et brouillons orphelins
        Route::get('doublons', [Admin\DoublonsController::class, 'index'])->name('doublons.index');
        Route::get('doublons/orphelins', [Admin\DoublonsController::class, 'orphelins'])->name('doublons.orphelins');
        Route::get('doublons/cibles', [Admin\DoublonsController::class, 'cibles'])->name('doublons.cibles');
        Route::post('doublons/ignorer', [Admin\DoublonsController::class, 'ignorer'])->name('doublons.ignorer');

        // BO-03 Programme de garde
        Route::get('gardes', [Admin\GardeController::class, 'saisie'])->name('gardes.saisie');
        Route::post('gardes', [Admin\GardeController::class, 'enregistrerSaisie'])->name('gardes.saisie.enregistrer');
        Route::get('gardes/declarations', [Admin\GardeDeclarationController::class, 'index'])->name('gardes.declarations.index');
        Route::post('gardes/declarations/{declaration}/valider', [Admin\GardeDeclarationController::class, 'valider'])->name('gardes.declarations.valider');
        Route::post('gardes/declarations/{declaration}/rejeter', [Admin\GardeDeclarationController::class, 'rejeter'])->name('gardes.declarations.rejeter');
        Route::get('gardes/historique', [Admin\GardeController::class, 'index'])->name('gardes.index');
        Route::get('gardes/import', [Admin\GardeController::class, 'create'])->name('gardes.create');
        Route::post('gardes/import', [Admin\GardeController::class, 'store'])->name('gardes.store');
        Route::get('gardes/modele', [Admin\GardeController::class, 'modele'])->name('gardes.modele');
        Route::get('gardes/lots/{lot}', [Admin\GardeController::class, 'show'])->name('gardes.show');
        Route::post('gardes/lots/{lot}/correspondances', [Admin\GardeController::class, 'corriger'])->name('gardes.corriger');
        Route::post('gardes/lots/{lot}/publier', [Admin\GardeController::class, 'publier'])->name('gardes.publier');
        Route::post('gardes/lots/{lot}/annuler', [Admin\GardeController::class, 'annuler'])->name('gardes.annuler');

        // BO-04 Fiches
        Route::get('fiches', [Admin\FicheController::class, 'index'])->name('fiches.index');
        Route::get('fiches/{fiche}', [Admin\FicheController::class, 'show'])->name('fiches.show');
        Route::post('fiches/{fiche}/valider', [Admin\FicheController::class, 'valider'])->name('fiches.valider');
        Route::post('fiches/{fiche}/rejeter', [Admin\FicheController::class, 'rejeter'])->name('fiches.rejeter');
        Route::get('fiches/{fiche}/pieces/{index}', [Admin\FicheController::class, 'piece'])->whereNumber('index')->name('fiches.piece');

        // Journal des alertes (suivi technique, sans contenu privé)
        Route::get('alertes', [Admin\AlerteController::class, 'index'])->name('alertes.index');
        Route::get('alertes/{alerte}', [Admin\AlerteController::class, 'show'])->whereNumber('alerte')->name('alertes.show');

        // F13 Avis et notes (modération)
        Route::get('avis', [Admin\AvisController::class, 'index'])->name('avis.index');
        Route::post('avis/{avis}/valider', [Admin\AvisController::class, 'valider'])->whereNumber('avis')->name('avis.valider');
        Route::post('avis/{avis}/rejeter', [Admin\AvisController::class, 'rejeter'])->whereNumber('avis')->name('avis.rejeter');
        Route::post('avis/{avis}/reponse/valider', [Admin\AvisController::class, 'validerReponse'])->whereNumber('avis')->name('avis.reponse.valider');
        Route::post('avis/{avis}/reponse/rejeter', [Admin\AvisController::class, 'rejeterReponse'])->whereNumber('avis')->name('avis.reponse.rejeter');

        // BO-05 Messagerie support
        Route::get('messages', [Admin\MessageController::class, 'index'])->name('messages.index');
        Route::get('messages/{message}', [Admin\MessageController::class, 'show'])->name('messages.show');
        Route::post('messages/{message}/repondre', [Admin\MessageController::class, 'repondre'])->name('messages.repondre');

        // Réservé aux administrateurs
        Route::middleware('role:admin')->group(function () {
            Route::get('alertes/export', [Admin\AlerteController::class, 'export'])->name('alertes.export');
            Route::post('doublons/fusionner', [Admin\DoublonsController::class, 'fusionner'])->name('doublons.fusionner');
            Route::delete('etablissements/{etablissement}', [Admin\EtablissementController::class, 'destroy'])->name('etablissements.destroy');
            // BO-06 Régie publicitaire
            Route::resource('publicites', Admin\PubliciteController::class)->except('show');
            // F17 Fiches Premium
            Route::get('premium', [Admin\PremiumController::class, 'index'])->name('premium.index');
            Route::get('premium/{etablissement}', [Admin\PremiumController::class, 'edit'])->name('premium.edit');
            Route::put('premium/{etablissement}', [Admin\PremiumController::class, 'update'])->name('premium.update');
            Route::post('premium/{etablissement}/activer', [Admin\PremiumController::class, 'activer'])->name('premium.activer');
            Route::post('premium/{etablissement}/photos', [Admin\PremiumController::class, 'ajouterPhoto'])->name('premium.photos.ajouter');
            Route::delete('premium/{etablissement}/photos/{photo}', [Admin\PremiumController::class, 'supprimerPhoto'])->name('premium.photos.supprimer');
            // F19 Abonnements famille et paiements
            Route::get('abonnements', [Admin\AbonnementController::class, 'index'])->name('abonnements.index');
            Route::post('abonnements/accorder', [Admin\AbonnementController::class, 'accorder'])->name('abonnements.accorder');
            Route::post('abonnements/plans', [Admin\AbonnementController::class, 'planEnregistrer'])->name('abonnements.plans.creer');
            Route::post('abonnements/plans/{plan}', [Admin\AbonnementController::class, 'planEnregistrer'])->whereNumber('plan')->name('abonnements.plans.maj');
            Route::post('abonnements/{abonnement}/annuler', [Admin\AbonnementController::class, 'annuler'])->whereNumber('abonnement')->name('abonnements.annuler');
            Route::get('utilisateurs',[Admin\UtilisateurController::class, 'index'])->name('utilisateurs.index');
            Route::put('utilisateurs/{user}', [Admin\UtilisateurController::class, 'update'])->name('utilisateurs.update');
            Route::get('journal', [Admin\JournalController::class, 'index'])->name('journal.index');
        });
    });
});
