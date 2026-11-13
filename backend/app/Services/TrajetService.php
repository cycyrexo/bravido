<?php

namespace App\Services;

use App\Models\Complice;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * « Je rentre chez moi » : trajet surveillé. Sans confirmation d'arrivée à l'échéance + délai de grâce,
 * une vraie alerte (motif « Trajet non confirmé ») part vers les complices choisis avec la dernière position connue.
 */
class TrajetService
{
    public const LIBELLE_ALERTE = 'Trajet non confirmé';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly AlerteService $alertes,
    ) {}

    public function demarrer(User $user, array $data): Trajet
    {
        $complices = $user->complices()->where('statut', 'accepte')
            ->when(! empty($data['complice_ids']), fn ($q) => $q->whereIn('id', $data['complice_ids']))
            ->get();
        if ($complices->isEmpty()) {
            throw ValidationException::withMessages([
                'complice_ids' => 'Aucun complice (ayant accepté) n\'est sélectionné pour suivre votre trajet.',
            ]);
        }

        $maintenant = now();
        $trajet = new Trajet([
            'partage_position' => (bool) ($data['partage_position'] ?? false),
            'complice_ids' => $complices->pluck('id')->all(),
        ]);
        $trajet->user()->associate($user);
        $trajet->statut = 'en_cours';
        $trajet->started_at = $maintenant;
        $trajet->echeance_at = $maintenant->copy()->addMinutes((int) $data['duree_minutes']);
        $trajet->save();

        if (isset($data['lat'], $data['lng'])) {
            $this->enregistrerPosition($trajet, (float) $data['lat'], (float) $data['lng'], $data['precision_m'] ?? null);
        }

        $nom = $user->nomAffiche();
        $heure = $trajet->echeance_at->format('H:i');
        foreach ($complices as $complice) {
            $this->notifier->push($complice->compliceUser, 'BRAVIDO : trajet surveillé',
                "{$nom} rentre chez lui/elle (arrivée prévue vers {$heure}). Vous serez prévenu(e) s'il/elle ne confirme pas son arrivée.",
                ['type' => 'trajet_debut', 'trajet_id' => $trajet->id]);
        }

        return $trajet;
    }

    public function enregistrerPosition(Trajet $trajet, float $lat, float $lng, ?int $precision): void
    {
        $maintenant = now();
        $trajet->positions()->create(['lat' => $lat, 'lng' => $lng, 'precision_m' => $precision, 'recorded_at' => $maintenant]);
        $trajet->forceFill(['lat' => $lat, 'lng' => $lng, 'precision_m' => $precision, 'position_at' => $maintenant])->save();

        // Trajet déjà escaladé : la position alimente aussi l'alerte en cours (suivi par les complices)
        if ($trajet->statut === 'alerte' && $trajet->alerte && $trajet->alerte->estActive()) {
            $trajet->alerte->positions()->create(['lat' => $lat, 'lng' => $lng, 'precision_m' => $precision, 'recorded_at' => $maintenant]);
        }
    }

    /** +15 min, 2 fois maximum, tant que l'alerte n'est pas partie. */
    public function prolonger(Trajet $trajet): bool
    {
        if (! $trajet->estEnCours() || $trajet->extensions >= config('bravido.trajet.prolongations_max')) {
            return false;
        }

        $base = $trajet->echeance_at->isFuture() ? $trajet->echeance_at : now();
        $trajet->forceFill([
            'echeance_at' => $base->copy()->addMinutes(config('bravido.trajet.prolongation_minutes')),
            'extensions' => $trajet->extensions + 1,
            'rappel_envoye_at' => null,
        ])->save();

        return true;
    }

    /** « Je suis arrivé » : annule la surveillance (et l'alerte automatique si elle est déjà partie). */
    public function arriver(Trajet $trajet): Trajet
    {
        if ($trajet->statut === 'arrive') {
            return $trajet;
        }

        $etaitEnAlerte = $trajet->statut === 'alerte';
        $trajet->forceFill(['statut' => 'arrive', 'arrive_at' => now()])->save();

        if ($etaitEnAlerte && $trajet->alerte && $trajet->alerte->statut === 'active') {
            // Fausse alerte : les complices reçoivent « alerte terminée »
            $this->alertes->terminer($trajet->alerte->load('user'), 'annulee');

            return $trajet;
        }

        $nom = $trajet->user->nomAffiche();
        foreach ($this->complicesDuTrajet($trajet) as $complice) {
            $this->notifier->push($complice->compliceUser, 'BRAVIDO : trajet terminé',
                "{$nom} est bien arrivé(e).", ['type' => 'trajet_arrive', 'trajet_id' => $trajet->id]);
        }

        return $trajet;
    }

    /** Appelé chaque minute par bravido:verifier-trajets. Retourne [rappels, alertes]. */
    public function verifierEcheances(): array
    {
        $rappels = $alertes = 0;

        Trajet::where('statut', 'en_cours')
            ->where('echeance_at', '<=', now())
            ->with('user')
            ->each(function (Trajet $trajet) use (&$rappels, &$alertes) {
                // 1) Rappel à l'échéance, une seule fois (verrou atomique)
                if ($trajet->rappel_envoye_at === null) {
                    $verrou = Trajet::whereKey($trajet->id)->whereNull('rappel_envoye_at')->update(['rappel_envoye_at' => now()]);
                    if ($verrou === 1) {
                        $minutes = config('bravido.trajet.grace_minutes');
                        $this->notifier->push($trajet->user, 'Êtes-vous bien arrivé ?',
                            "Confirmez votre arrivée dans BRAVIDO. Sans réponse dans {$minutes} minutes, vos complices seront alertés.",
                            ['type' => 'trajet_rappel', 'trajet_id' => $trajet->id]);
                        $rappels++;
                    }
                }

                // 2) Fin du délai de grâce : alerte réelle
                if (now()->gte($trajet->echeance_at->copy()->addMinutes(config('bravido.trajet.grace_minutes')))) {
                    if ($this->escalader($trajet)) {
                        $alertes++;
                    }
                }
            });

        return [$rappels, $alertes];
    }

    private function escalader(Trajet $trajet): bool
    {
        // Verrou atomique : une seule alerte par trajet, même si la commande se chevauche
        $verrou = Trajet::whereKey($trajet->id)->where('statut', 'en_cours')->update(['statut' => 'alerte']);
        if ($verrou === 0) {
            return false;
        }
        $trajet->refresh();

        try {
            $alerte = $this->alertes->declencher($trajet->user, [
                'motif' => 'autre',
                'motif_detail' => self::LIBELLE_ALERTE,
                'lat' => $trajet->lat,
                'lng' => $trajet->lng,
                'precision_m' => $trajet->precision_m,
                'complice_ids' => $trajet->complice_ids,
                'origine' => 'trajet',
            ]);
            $trajet->forceFill(['alerte_id' => $alerte->id])->save();
        } catch (ValidationException $e) {
            // Plus aucun complice joignable : on prévient au moins la personne
            Log::warning("Trajet {$trajet->id} : alerte impossible", $e->errors());
            $this->notifier->push($trajet->user, 'BRAVIDO : alerte impossible',
                'Votre trajet n\'a pas été confirmé mais aucun complice n\'a pu être alerté. Appelez le 185 ou le 180 si besoin.',
                ['type' => 'trajet_rappel', 'trajet_id' => $trajet->id]);
        }

        return true;
    }

    /** @return \Illuminate\Support\Collection<int, Complice> */
    public function complicesDuTrajet(Trajet $trajet)
    {
        return Complice::where('user_id', $trajet->user_id)
            ->where('statut', 'accepte')
            ->when($trajet->complice_ids, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->with('compliceUser')
            ->get();
    }
}
