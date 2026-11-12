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

        // Données fictives : uniquement en local, jamais en production
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
