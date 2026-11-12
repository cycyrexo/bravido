<?php

namespace Tests\Unit;

use App\Support\Telephone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelephoneTest extends TestCase
{
    public static function numeros(): array
    {
        return [
            'local 10 chiffres' => ['07 07 12 34 56', '+2250707123456'],
            'avec points' => ['07.07.12.34.56', '+2250707123456'],
            'indicatif 00' => ['002250707123456', '+2250707123456'],
            'indicatif sans +' => ['2250707123456', '+2250707123456'],
            'déjà E.164' => ['+2250707123456', '+2250707123456'],
            'étranger' => ['+33612345678', '+33612345678'],
            'trop court' => ['123', null],
            'vide' => ['', null],
        ];
    }

    #[DataProvider('numeros')]
    public function test_normalisation(string $brut, ?string $attendu): void
    {
        $this->assertSame($attendu, Telephone::normaliser($brut));
    }
}
