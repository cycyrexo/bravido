<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Telephone;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        $telephone = Telephone::normaliser(env('ADMIN_TELEPHONE'));

        if (! $email || ! $password || ! $telephone) {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD / ADMIN_TELEPHONE manquants : aucun administrateur créé.');

            return;
        }

        $admin = User::firstOrNew(['email' => $email]);
        $admin->telephone = $admin->telephone ?: $telephone;
        $admin->nom = $admin->nom ?: 'Administrateur BRAVIDO';
        $admin->role = 'admin';
        $admin->statut = 'actif';
        if (! $admin->exists) {
            $admin->password = $password;
        }
        $admin->save();

        $this->command?->info("Administrateur : {$email}");
    }
}
