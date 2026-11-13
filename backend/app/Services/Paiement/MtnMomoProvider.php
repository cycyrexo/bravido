<?php

namespace App\Services\Paiement;

class MtnMomoProvider extends FournisseurNonConfigure
{
    public function code(): string
    {
        return 'mtn_momo';
    }

    public function libelle(): string
    {
        return 'MTN MoMo';
    }

    protected function clesRequises(): array
    {
        return ['subscription_key', 'api_user', 'api_key', 'webhook_secret'];
    }
}
