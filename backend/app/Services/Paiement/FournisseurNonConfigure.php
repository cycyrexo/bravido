<?php

namespace App\Services\Paiement;

use App\Models\Paiement;

/**
 * Base des adaptateurs réels (Orange Money, MTN MoMo, Wave) : VIDES tant que le compte marchand et l'API
 * du fournisseur n'ont pas été obtenus. Chaque appel échoue avec un message clair.
 */
abstract class FournisseurNonConfigure implements PaymentProvider
{
    /** Clés de config/services.php (paiement.<code>.<cle>) indispensables. */
    abstract protected function clesRequises(): array;

    public function configure(): bool
    {
        // Même avec les clés, l'appel réel à l'API du fournisseur reste à écrire : jamais « configuré » ici
        return false;
    }

    public function initier(Paiement $paiement): string
    {
        $this->refuser();
    }

    public function lireWebhook(string $corps, array $entetes): array
    {
        $this->refuser();
    }

    private function refuser(): never
    {
        $manquantes = array_filter($this->clesRequises(), fn ($k) => ! config("services.paiement.{$this->code()}.{$k}"));
        throw new PaiementNonConfigureException(
            $manquantes === []
                ? "{$this->libelle()} : adaptateur non finalisé (clés présentes, appel à l'API du fournisseur à implémenter)."
                : "{$this->libelle()} n'est pas configuré : identifiants marchands manquants (".implode(', ', $manquantes).').'
        );
    }
}
