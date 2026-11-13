<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferentielSeeder::class,
            AdminSeeder::class,
        ]);

        // Données fictives « DÉMO » : plus chargées automatiquement (l'annuaire réel est importé).
        // Au besoin : php artisan db:seed --class=DemoSeeder (jamais en production).
    }
}
