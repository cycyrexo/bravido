<?php

namespace App\Services\Paiement;

class OrangeMoneyProvider extends FournisseurNonConfigure
{
    public function code(): string
    {
        return 'orange_money';
    }

    public function libelle(): string
    {
        return 'Orange Money';
    }

    protected function clesRequises(): array
    {
        return ['client_id', 'client_secret', 'merchant_key', 'webhook_secret'];
    }
}
