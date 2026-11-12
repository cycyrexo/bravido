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

        // BO-03 Programme de garde
        Route::get('gardes', [Admin\GardeController::class, 'saisie'])->name('gardes.saisie');
        Route::post('gardes', [Admin\GardeController::class, 'enregistrerSaisie'])->name('gardes.saisie.enregistrer');
        Route::get('gardes/historique', [Admin\GardeController::class, 'index'])->name('gardes.index');
        Route::get('gardes/import', [Admin\GardeController::class, 'create'])->name('gardes.create');
        Route::post('gardes/import', [Admin\GardeController::class, 'store'])->name('gardes.store');
        Route::get('gardes/modele', [Admin\GardeController::class, 'modele'])->name('gardes.modele');
        Route::get('gardes/lots/{lot}', [Admin\GardeController::class, 'show'])->name('gardes.show');
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

        // BO-05 Messagerie support
        Route::get('messages', [Admin\MessageController::class, 'index'])->name('messages.index');
        Route::get('messages/{message}', [Admin\MessageController::class, 'show'])->name('messages.show');
        Route::post('messages/{message}/repondre', [Admin\MessageController::class, 'repondre'])->name('messages.repondre');

        // Réservé aux administrateurs
        Route::middleware('role:admin')->group(function () {
            Route::delete('etablissements/{etablissement}', [Admin\EtablissementController::class, 'destroy'])->name('etablissements.destroy');
            // BO-06 Régie publicitaire
            Route::resource('publicites', Admin\PubliciteController::class)->except('show');
            Route::get('utilisateurs', [Admin\UtilisateurController::class, 'index'])->name('utilisateurs.index');
            Route::put('utilisateurs/{user}', [Admin\UtilisateurController::class, 'update'])->name('utilisateurs.update');
            Route::get('journal', [Admin\JournalController::class, 'index'])->name('journal.index');
        });
    });
});
