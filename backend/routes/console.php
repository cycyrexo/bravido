<?php

use App\Models\Alerte;
use App\Models\AlerteAudio;
use App\Models\AlerteEvenement;
use App\Models\AlertePosition;
use App\Models\OtpCode;
use App\Models\Trajet;
use App\Models\TrajetPosition;
use App\Services\AlerteService;
use App\Services\TrajetService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Tâches planifiées. En production, une seule entrée cron suffit :
|   * * * * * cd /chemin/backend && php artisan schedule:run >> /dev/null 2>&1
| Sous Windows (WAMP) : Planificateur de tâches, toutes les minutes.
*/

Artisan::command('bravido:purger', function () {
    $limite = now()->subDays(config('bravido.alerte.retention_jours'));

    // Exigence sécurité : positions d'alerte effacées après 30 jours (par paquets)
    $total = 0;
    do {
        $supprimees = AlertePosition::where('recorded_at', '<', $limite)->limit(5000)->delete();
        $total += $supprimees;
    } while ($supprimees > 0);

    $anonymisees = Alerte::where('started_at', '<', $limite)
        ->whereNotNull('lat')
        ->update(['lat' => null, 'lng' => null, 'precision_m' => null]);

    $otp = OtpCode::where('created_at', '<', now()->subDay())->delete();

    // Messages vocaux : fichiers privés + lignes supprimés après 30 jours
    $audios = 0;
    AlerteAudio::where('created_at', '<', $limite)->each(function (AlerteAudio $audio) use (&$audios) {
        $audio->supprimerFichier();
        $audio->delete();
        $audios++;
    });

    // Trajets surveillés : positions supprimées, dernière position effacée, trajets terminés supprimés
    $positionsTrajet = 0;
    do {
        $supprimees = TrajetPosition::where('recorded_at', '<', $limite)->limit(5000)->delete();
        $positionsTrajet += $supprimees;
    } while ($supprimees > 0);
    Trajet::where('started_at', '<', $limite)->whereNotNull('lat')->update(['lat' => null, 'lng' => null, 'precision_m' => null]);
    $trajets = Trajet::where('started_at', '<', $limite)->where('statut', '!=', 'en_cours')->delete();

    // Positions proposées par les utilisateurs (F2) : supprimées après 90 jours
    $propositions = app(\App\Services\PositionsUtilisateurs::class)->purgerAnciennes();

    // F22 : journal d'audit des alertes conservé 12 mois (aucune donnée personnelle)
    $evenements = AlerteEvenement::where('created_at', '<', now()->subMonths(config('bravido.alerte.retention_audit_mois')))->delete();

    $this->info("Événements d'audit : {$evenements} · Propositions de position : {$propositions} · Positions supprimées : {$total} · alertes anonymisées : {$anonymisees} · OTP purgés : {$otp} · messages vocaux : {$audios} · positions de trajet : {$positionsTrajet} · trajets : {$trajets}");
})->purpose('Purge des positions d\'alerte, messages vocaux et trajets (> 30 jours) et des codes OTP');

Artisan::command('bravido:cloturer-alertes', function (AlerteService $service) {
    $n = 0;
    Alerte::where('statut', 'active')
        ->where('expires_at', '<=', now())
        ->with('user')
        ->each(function (Alerte $alerte) use ($service, &$n) {
            $service->terminer($alerte, 'close', 'expiree');
            $n++;
        });

    $this->info("Alertes clôturées : {$n}");
})->purpose('Clôture les alertes arrivées au bout de leur durée (1 h)');

Artisan::command('bravido:verifier-trajets', function (TrajetService $service) {
    [$rappels, $alertes] = $service->verifierEcheances();

    $this->info("Rappels « Êtes-vous bien arrivé ? » : {$rappels} · alertes automatiques : {$alertes}");
})->purpose('Rappel à l\'échéance puis alerte automatique si le trajet n\'est pas confirmé (délai de grâce 2 min)');

// F23 : battement de cœur du planificateur (surveillé par /sante et la carte « Santé du système »)
Artisan::command('bravido:heartbeat', function (\App\Services\SanteSysteme $sante) {
    $sante->battement();
    $this->info('Battement enregistré.');
})->purpose('Enregistre l\'horodatage du planificateur (supervision)');

Artisan::command('bravido:expirer-abonnements', function (\App\Services\PaiementService $paiements) {
    $this->info('Abonnements expirés : '.$paiements->expirer());
})->purpose('Passe en « expiré » les abonnements famille arrivés à échéance (F19)');

Schedule::command('bravido:expirer-abonnements')->hourly();
Schedule::command('bravido:heartbeat')->everyMinute();
Schedule::command('bravido:verifier-trajets')->everyMinute()->withoutOverlapping();
Schedule::command('bravido:cloturer-alertes')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bravido:purger')->dailyAt('02:30');
Schedule::command('queue:prune-failed --hours=168')->daily();
