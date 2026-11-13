<?php

namespace App\Services\Paiement;

class WaveProvider extends FournisseurNonConfigure
{
    public function code(): string
    {
        return 'wave';
    }

    public function libelle(): string
    {
        return 'Wave';
    }

    protected function clesRequises(): array
    {
        return ['api_key', 'webhook_secret'];
    }
}
