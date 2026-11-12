<?php

use App\Models\Alerte;
use App\Models\AlertePosition;
use App\Models\OtpCode;
use App\Services\AlerteService;
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

    $this->info("Positions supprimées : {$total} · alertes anonymisées : {$anonymisees} · OTP purgés : {$otp}");
})->purpose('Purge des positions d\'alerte (> 30 jours) et des codes OTP');

Artisan::command('bravido:cloturer-alertes', function (AlerteService $service) {
    $n = 0;
    Alerte::where('statut', 'active')
        ->where('expires_at', '<=', now())
        ->with('user')
        ->each(function (Alerte $alerte) use ($service, &$n) {
            $service->terminer($alerte, 'close');
            $n++;
        });

    $this->info("Alertes clôturées : {$n}");
})->purpose('Clôture les alertes arrivées au bout de leur durée (1 h)');

Schedule::command('bravido:cloturer-alertes')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bravido:purger')->dailyAt('02:30');
Schedule::command('queue:prune-failed --hours=168')->daily();
