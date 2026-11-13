<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * F23 : état de santé du système (base, file d'attente, planificateur, FCM, disque).
 * Chaque contrôle renvoie ['ok' => bool, 'libelle' => string, 'detail' => string].
 */
class SanteSysteme
{
    public const CLE_HEARTBEAT = 'bravido.heartbeat';

    /** Seuils d'alerte. */
    public const FILE_AGE_MAX_S = 120;

    public const HEARTBEAT_AGE_MAX_S = 180;

    public const DISQUE_MIN_OCTETS = 1_073_741_824; // 1 Go

    public function battement(): void
    {
        Cache::forever(self::CLE_HEARTBEAT, now()->timestamp);
    }

    /** @return array<string, array{ok: bool, libelle: string, detail: string}> */
    public function controles(): array
    {
        return [
            'db' => $this->base(),
            'file' => $this->file(),
            'echecs' => $this->echecs(),
            'planificateur' => $this->planificateur(),
            'fcm' => $this->fcm(),
            'disque' => $this->disque(),
        ];
    }

    /** Version minimale pour GET /sante (aucun détail interne). */
    public function resume(): array
    {
        $c = $this->controles();

        return [
            // FCM absent ou travaux en échec : signalés dans le back-office, mais ne rendent pas le service « dégradé »
            // (le SMS de secours prend le relais du push)
            'status' => collect($c)->only(['db', 'file', 'planificateur', 'disque'])->every(fn ($x) => $x['ok']) ? 'ok' : 'degrade',
            'db' => $c['db']['ok'],
            'queue' => $c['file']['ok'],
            'scheduler' => $c['planificateur']['ok'],
            'time' => now()->toIso8601String(),
        ];
    }

    public function base(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'libelle' => 'Base de données', 'detail' => 'Connexion OK'];
        } catch (Throwable) {
            return ['ok' => false, 'libelle' => 'Base de données', 'detail' => 'Connexion impossible'];
        }
    }

    /** Taille de la file et ancienneté du plus ancien travail en attente. */
    public function file(): array
    {
        $libelle = 'File d\'attente';
        if (config('queue.default') === 'sync') {
            return ['ok' => true, 'libelle' => $libelle, 'detail' => 'Mode synchrone (pas de file)'];
        }
        try {
            $attente = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp);
            $nb = (clone $attente)->count();
            $plusAncien = (clone $attente)->min('available_at');
            $age = $plusAncien ? max(0, now()->timestamp - (int) $plusAncien) : 0;
            $ok = $age <= self::FILE_AGE_MAX_S;

            return ['ok' => $ok, 'libelle' => $libelle, 'detail' => "{$nb} en attente · plus ancien : {$age} s".($ok ? '' : ' (le worker semble bloqué)')];
        } catch (Throwable) {
            return ['ok' => false, 'libelle' => $libelle, 'detail' => 'Table des travaux illisible'];
        }
    }

    public function echecs(): array
    {
        try {
            $nb = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        } catch (Throwable) {
            return ['ok' => false, 'libelle' => 'Travaux en échec', 'detail' => 'Illisible'];
        }

        return ['ok' => $nb === 0, 'libelle' => 'Travaux en échec', 'detail' => $nb === 0 ? 'Aucun' : "{$nb} à examiner (php artisan queue:failed)"];
    }

    public function planificateur(): array
    {
        $ts = Cache::get(self::CLE_HEARTBEAT);
        if (! $ts) {
            return ['ok' => false, 'libelle' => 'Planificateur', 'detail' => 'Aucun battement reçu (tâche planifiée à enregistrer ?)'];
        }
        $age = max(0, now()->timestamp - (int) $ts);
        $ok = $age <= self::HEARTBEAT_AGE_MAX_S;

        return ['ok' => $ok, 'libelle' => 'Planificateur', 'detail' => "Dernier battement il y a {$age} s".($ok ? '' : ' (planificateur arrêté ?)')];
    }

    /** Push FCM : fichier d'identifiants présent + jeton OAuth récemment obtenu (mis en cache par l'envoi). */
    public function fcm(): array
    {
        $projet = config('services.fcm.project_id');
        $fichier = config('services.fcm.credentials');
        if (! $projet || ! $fichier) {
            return ['ok' => false, 'libelle' => 'Notifications push (FCM)', 'detail' => 'Non configuré : les complices reçoivent le SMS de secours'];
        }
        if (! is_file($fichier)) {
            return ['ok' => false, 'libelle' => 'Notifications push (FCM)', 'detail' => 'Fichier d\'identifiants introuvable'];
        }
        $jeton = Cache::has('fcm_access_token');

        return ['ok' => true, 'libelle' => 'Notifications push (FCM)', 'detail' => 'Identifiants présents · '.($jeton ? 'jeton valide en cache' : 'jeton demandé au prochain envoi')];
    }

    public function disque(): array
    {
        $libre = @disk_free_space(storage_path());
        if ($libre === false) {
            return ['ok' => false, 'libelle' => 'Espace disque', 'detail' => 'Illisible'];
        }
        $go = round($libre / 1_073_741_824, 1);

        return ['ok' => $libre >= self::DISQUE_MIN_OCTETS, 'libelle' => 'Espace disque', 'detail' => "{$go} Go libres"];
    }
}
