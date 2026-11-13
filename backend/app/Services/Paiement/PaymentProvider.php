<?php

namespace App\Services\Paiement;

use App\Models\Paiement;

/**
 * F19 : fournisseur de paiement mobile (Orange Money, MTN MoMo, Wave) ou bac à sable.
 * Un fournisseur n'a aucun accès aux alertes : il ne sait qu'initier un paiement et lire une notification signée.
 */
interface PaymentProvider
{
    /** Code court utilisé dans les URL (sandbox, orange_money, mtn_momo, wave). */
    public function code(): string;

    public function libelle(): string;

    /** Identifiants marchands présents (et adaptateur opérationnel). */
    public function configure(): bool;

    /**
     * Prépare le paiement chez le fournisseur et retourne l'URL de paiement à ouvrir.
     *
     * @throws PaiementNonConfigureException
     */
    public function initier(Paiement $paiement): string;

    /**
     * Vérifie la signature d'une notification (webhook) et la décode.
     *
     * @param  array<string, string>  $entetes  en-têtes en minuscules
     * @return array{reference: string, statut: 'reussi'|'echoue', montant?: int, payload: array}
     *
     * @throws SignatureInvalideException
     * @throws PaiementNonConfigureException
     */
    public function lireWebhook(string $corps, array $entetes): array;
}
