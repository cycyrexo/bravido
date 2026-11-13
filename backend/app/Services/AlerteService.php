<?php

namespace App\Services;

use App\Jobs\EnvoyerAlerteDestinataire;
use App\Models\Alerte;
use App\Models\AlerteDestinataire;
use App\Models\AlerteEvenement;
use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * URG-02 à URG-04 : déclenchement, diffusion et suivi de l'alerte.
 * Canal : push d'abord ; SMS de secours si pas d'appli ou push non accusé.
 */
class AlerteService
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly SmsSender $sms,
    ) {}

    public function declencher(User $user, array $data): Alerte
    {
        // Une seule alerte active à la fois : un double appui renvoie l'alerte en cours
        $enCours = $user->alertes()
            ->where('statut', 'active')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
        if ($enCours) {
            return $enCours;
        }

        $complices = $user->complices()->where('statut', 'accepte')
            ->when(! empty($data['complice_ids']), fn ($q) => $q->whereIn('id', $data['complice_ids']))
            ->get();
        if ($complices->isEmpty()) {
            throw ValidationException::withMessages([
                'complices' => 'Aucun complice n\'a encore accepté votre invitation. Appelez le 185 (SAMU) ou le 180 (pompiers).',
            ]);
        }

        $alerte = DB::transaction(function () use ($user, $data, $complices) {
            $maintenant = now();

            $alerte = new Alerte([
                'motif' => $data['motif'],
                'motif_detail' => $data['motif_detail'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'precision_m' => $data['precision_m'] ?? null,
                'statut' => 'active',
                'lien_token' => Str::random(40),
                'started_at' => $maintenant,
                'expires_at' => $maintenant->copy()->addMinutes(config('bravido.alerte.duree_minutes')),
            ]);
            $alerte->user()->associate($user);
            $alerte->save();

            if (isset($data['lat'], $data['lng'])) {
                $alerte->positions()->create([
                    'lat' => $data['lat'],
                    'lng' => $data['lng'],
                    'precision_m' => $data['precision_m'] ?? null,
                    'recorded_at' => $maintenant,
                ]);
            }

            foreach ($complices as $complice) {
                $alerte->destinataires()->create([
                    'complice_id' => $complice->id,
                    'nom' => $complice->nom,
                    'telephone' => $complice->telephone,
                    'destinataire_user_id' => $complice->complice_user_id,
                ]);
            }

            return $alerte;
        });

        AlerteEvenement::noter($alerte->id, 'declenchee', null, ($data['origine'] ?? null) === 'trajet' ? 'systeme' : 'app');
        if (($data['origine'] ?? null) === 'trajet') {
            AlerteEvenement::noter($alerte->id, 'trajet_auto', null, 'systeme');
        }
        foreach ($alerte->destinataires as $destinataire) {
            EnvoyerAlerteDestinataire::dispatch($destinataire->id)->onQueue('alertes');
        }

        return $alerte;
    }

    /** SMS de secours, envoyé au plus une fois par destinataire. */
    public function envoyerSmsSecours(AlerteDestinataire $destinataire): bool
    {
        $verrou = AlerteDestinataire::whereKey($destinataire->id)
            ->whereNull('sms_envoye_at')
            ->update(['sms_envoye_at' => now()]);
        if ($verrou === 0) {
            return false;
        }

        $alerte = $destinataire->alerte()->with('user')->first();
        $nom = $alerte->user->nomAffiche();

        $message = "ALERTE BRAVIDO : {$nom} ({$alerte->user->telephone}) a besoin d'aide ({$alerte->libelleMotif()}). "
            ."Position : {$alerte->lienCarte()} . Si besoin appelez le 185 (SAMU) ou le 180 (pompiers).";

        $ok = $this->sms->envoyer($destinataire->telephone, $message);
        if ($ok) {
            AlerteEvenement::noter($alerte->id, 'sms_envoye', $destinataire, 'sms');
        }
        if (! $ok) {
            // Libère le verrou pour permettre une nouvelle tentative
            AlerteDestinataire::whereKey($destinataire->id)->update(['sms_envoye_at' => null]);
        }

        return $ok;
    }

    /** $cause : 'expiree' quand la clôture vient de la durée écoulée (planificateur). */
    public function terminer(Alerte $alerte, string $statut, ?string $cause = null): Alerte
    {
        if ($alerte->statut !== 'active') {
            return $alerte;
        }

        $alerte->forceFill(['statut' => $statut, 'closed_at' => now()])->save();
        AlerteEvenement::noter($alerte->id, $cause ?? ($statut === 'annulee' ? 'annulee' : 'cloturee'), null, $cause === 'expiree' ? 'systeme' : 'app');

        $nom = $alerte->user->nomAffiche();
        $corps = $statut === 'annulee'
            ? "{$nom} a annulé son alerte (déclenchement par erreur)."
            : "{$nom} a clôturé son alerte.";

        foreach ($alerte->destinataires()->with('destinataireUser')->get() as $destinataire) {
            $this->notifier->push($destinataire->destinataireUser, 'BRAVIDO : alerte terminée', $corps, [
                'type' => 'alerte_terminee',
                'alerte_id' => $alerte->id,
            ]);
        }

        return $alerte;
    }
}
