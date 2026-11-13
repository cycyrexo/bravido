<?php

namespace Tests\Support;

use App\Models\Categorie;
use App\Models\Commune;
use App\Models\Etablissement;
use App\Models\User;
use Database\Seeders\ReferentielSeeder;

/** Aides communes aux tests du back-office (données de référence + administrateur + établissements). */
trait AdminTestHelpers
{
    protected function preparer(): User
    {
        $this->seed(ReferentielSeeder::class);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin', 'statut' => 'actif', 'email' => 'admin@test.ci'])->save();
        $this->actingAs($admin);

        return $admin;
    }

    protected function etab(string $nom, array $attrs = [], string $categorie = 'pharmacie', string $commune = 'Cocody'): Etablissement
    {
        return Etablissement::create($attrs + [
            'categorie_id' => Categorie::idPour($categorie),
            'nom' => $nom,
            'commune_id' => Commune::where('nom', $commune)->value('id'),
            'lat' => 5.36, 'lng' => -4.0083, 'position_precise' => false, 'statut' => 'publie',
        ]);
    }
}
