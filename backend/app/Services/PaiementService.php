<?php

namespace App\Services;

use App\Models\Abonnement;
use App\Models\Paiement;
use App\Models\Plan;
use App\Models\User;
use App\Services\Paiement\MtnMomoProvider;
use App\Services\Paiement\OrangeMoneyProvider;
use App\Services\Paiement\PaymentProvider;
use App\Services\Paiement\SandboxProvider;
use App\Services\Paiement\WaveProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * F19 : souscription, notification de paiement (idempotente) et activation de l'abonnement.
 * RÈGLE PRODUIT : ce service n'est JAMAIS appelé par l'envoi d'alertes ; l'abonnement n'ouvre que des avantages
 * non critiques (profils famille, historique étendu, support prioritaire).
 */
class PaiementService
{
    /** @return array<string, PaymentProvider> */
    public function fournisseurs(): array
    {
        return collect([new SandboxProvider, new OrangeMoneyProvider, new MtnMomoProvider, new WaveProvider])
            ->mapWithKeys(fn (PaymentProvider $p) => [$p->code() => $p])->all();
    }

    public function fournisseur(string $code): PaymentProvider
    {
        return $this->fournisseurs()[$code] ?? throw new InvalidArgumentException("Fournisseur inconnu : {$code}");
    }

    /**
     * Crée l'intention de paiement puis demande l'URL au fournisseur. Si le fournisseur n'est pas configuré,
     * l'exception est propagée et AUCUN paiement n'est conservé.
     *
     * @return array{paiement: Paiement, url: string}
     */
    public function souscrire(User $user, Plan $plan, string $codeFournisseur): array
    {
        abort_unless($plan->actif, 422, 'Ce plan n\'est plus proposé.');
        $fournisseur = $this->fournisseur($codeFournisseur);

        return DB::transaction(function () use ($user, $plan, $fournisseur) {
            $p = new Paiement;
            $p->reference = 'BRV-'.strtoupper(Str::random(14));
            $p->user_id = $user->id;
            $p->plan_id = $plan->id;
            $p->montant = $plan->prix_xof;
            $p->devise = 'XOF';
            $p->fournisseur = $fournisseur->code();
            $p->statut = 'en_attente';
            $p->save();

            // En cas d'exception (non configuré), la transaction est annulée : pas de paiement fantôme
            $url = $fournisseur->initier($p);

            return ['paiement' => $p, 'url' => $url];
        });
    }

    /**
     * Notification du fournisseur : signature vérifiée par l'adaptateur, traitement idempotent par référence.
     *
     * @return array{statut: string, deja_traite: bool}
     */
    public function traiterWebhook(string $codeFournisseur, string $corps, array $entetes): array
    {
        $notif = $this->fournisseur($codeFournisseur)->lireWebhook($corps, $entetes);

        return DB::transaction(function () use ($codeFournisseur, $notif) {
            $paiement = Paiement::where('reference', $notif['reference'])->where('fournisseur', $codeFournisseur)->lockForUpdate()->first();
            abort_unless($paiement, 404, 'Paiement inconnu.');

            if ($paiement->statut !== 'en_attente') {
                return ['statut' => $paiement->statut, 'deja_traite' => true]; // rejeu : rien à refaire
            }
            if (isset($notif['montant']) && $notif['montant'] !== $paiement->montant) {
                $paiement->forceFill(['statut' => 'echoue', 'payload' => $notif['payload'] + ['anomalie' => 'montant_different']])->save();

                return ['statut' => 'echoue', 'deja_traite' => false];
            }

            $paiement->forceFill(['statut' => $notif['statut'], 'payload' => $notif['payload']])->save();
            if ($notif['statut'] === 'reussi') {
                $this->activer($paiement);
            }

            return ['statut' => $paiement->statut, 'deja_traite' => false];
        });
    }

    /** Active ou prolonge l'abonnement du payeur pour le plan acheté. */
    public function activer(Paiement $paiement): Abonnement
    {
        $plan = $paiement->plan;
        $existant = Abonnement::enCours()->where('user_id', $paiement->user_id)->where('plan_id', $plan->id)->latest('fin')->first();

        if ($existant) {
            $existant->fin = $existant->fin->copy()->addDays($plan->duree_jours);
            $abonnement = $existant;
        } else {
            $abonnement = new Abonnement;
            $abonnement->user_id = $paiement->user_id;
            $abonnement->plan_id = $plan->id;
            $abonnement->statut = 'actif';
            $abonnement->origine = 'paiement';
            $abonnement->debut = now();
            $abonnement->fin = now()->addDays($plan->duree_jours);
        }
        $abonnement->save();
        $paiement->forceFill(['abonnement_id' => $abonnement->id])->save();

        return $abonnement;
    }

    /** Marque comme expirés les abonnements dont la date de fin est passée. */
    public function expirer(): int
    {
        return Abonnement::where('statut', 'actif')->where('fin', '<=', now())->update(['statut' => 'expire']);
    }
}
