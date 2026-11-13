<?php

namespace App\Services\Paiement;

use App\Models\Paiement;

/**
 * Fournisseur SIMULÉ : l'URL de paiement est une page locale (/paiement/sandbox/{reference}) avec les boutons
 * « Simuler succès / échec ». La notification est signée HMAC-SHA256 comme le seront celles des vrais fournisseurs.
 * Désactivé en production (PAIEMENT_SANDBOX=false).
 */
class SandboxProvider implements PaymentProvider
{
    public const EN_TETE = 'x-bravido-signature';

    public function code(): string
    {
        return 'sandbox';
    }

    public function libelle(): string
    {
        return 'Paiement simulé (test)';
    }

    public function configure(): bool
    {
        return (bool) config('services.paiement.sandbox');
    }

    public function initier(Paiement $paiement): string
    {
        $this->verifier();

        return route('paiement.sandbox', $paiement->reference);
    }

    public function lireWebhook(string $corps, array $entetes): array
    {
        $this->verifier();
        $signature = $entetes[self::EN_TETE] ?? '';
        if ($signature === '' || ! hash_equals($this->signer($corps), $signature)) {
            throw new SignatureInvalideException('Signature invalide.');
        }
        $data = json_decode($corps, true);
        if (! is_array($data) || empty($data['reference']) || ! in_array($data['statut'] ?? null, ['reussi', 'echoue'], true)) {
            throw new SignatureInvalideException('Notification illisible.');
        }

        return ['reference' => (string) $data['reference'], 'statut' => $data['statut'], 'montant' => isset($data['montant']) ? (int) $data['montant'] : null, 'payload' => $data];
    }

    public function signer(string $corps): string
    {
        return hash_hmac('sha256', $corps, $this->secret());
    }

    private function secret(): string
    {
        return (string) (config('services.paiement.sandbox_secret') ?: hash('sha256', 'bravido-sandbox|'.config('app.key')));
    }

    private function verifier(): void
    {
        if (! $this->configure()) {
            throw new PaiementNonConfigureException('Le paiement simulé est désactivé sur ce serveur.');
        }
    }
}
