<?php

namespace Database\Seeders;

use App\Models\Assurance;
use App\Models\Categorie;
use App\Models\Commune;
use App\Models\Etablissement;
use App\Models\Garde;
use App\Models\Ville;
use Illuminate\Database\Seeder;

/**
 * Données FICTIVES pour le développement (préfixe « DÉMO »).
 * Les coordonnées sont approximatives (centre de commune).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $abidjan = Ville::where('nom', 'Abidjan')->firstOrFail();
        $communes = Commune::where('ville_id', $abidjan->id)->pluck('id', 'nom');
        $assurances = Assurance::pluck('id', 'nom');

        $centres = [
            'Plateau' => [5.3236, -4.0230], 'Cocody' => [5.3600, -3.9900], 'Yopougon' => [5.3400, -4.0800],
            'Marcory' => [5.3030, -3.9830], 'Treichville' => [5.2940, -4.0080], 'Adjamé' => [5.3600, -4.0200],
            'Abobo' => [5.4200, -4.0200], 'Koumassi' => [5.2970, -3.9480], 'Port-Bouët' => [5.2560, -3.9260],
        ];

        $debut = today()->startOfWeek();
        $fin = $debut->copy()->addDays(6);
        $i = 0;

        foreach ($centres as $commune => [$lat, $lng]) {
            foreach (['du Carrefour', 'de la Paix'] as $j => $suffixe) {
                $i++;
                $etab = Etablissement::updateOrCreate(
                    ['nom' => "DÉMO Pharmacie {$suffixe} {$commune}"],
                    [
                        'categorie_id' => Categorie::idPour('pharmacie'),
                        'commune_id' => $communes[$commune],
                        'adresse' => "Rue fictive n° {$i}, {$commune}",
                        'repere' => 'Près du marché',
                        'lat' => $lat + ($j * 0.004),
                        'lng' => $lng + ($j * 0.004),
                        'tel1' => '+22527'.str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT),
                        'horaires' => 'Lun-Sam 8h-21h',
                        'statut' => 'publie',
                        'verifie_at' => now(),
                    ]
                );
                $etab->assurances()->sync([$assurances['CNAM (CMU)'], $assurances['MUGEFCI']]);

                // Une pharmacie sur deux est de garde cette semaine
                if ($j === 0) {
                    Garde::firstOrCreate(
                        ['etablissement_id' => $etab->id, 'date_debut' => $debut->toDateString()],
                        ['date_fin' => $fin->toDateString(), 'source' => 'DÉMO']
                    );
                }
            }
        }

        $hopitaux = [
            ['DÉMO CHU de Cocody', 'hopital_public', 'Cocody', ['Urgences 24 h/24', 'Maternité', 'Pédiatrie', 'Chirurgie'], true],
            ['DÉMO CHU de Treichville', 'hopital_public', 'Treichville', ['Urgences 24 h/24', 'Cardiologie', 'Réanimation'], true],
            ['DÉMO Clinique du Plateau', 'hopital_prive', 'Plateau', ['Médecine générale', 'Radiologie', 'Laboratoire'], false],
            ['DÉMO Hôpital militaire', 'militaire', 'Yopougon', ['Urgences 24 h/24', 'Chirurgie'], true],
            ['DÉMO Centre de secours GSPM Indénié', 'centre_secours', 'Plateau', [], true],
            ['DÉMO Bureau de liaison FGA', 'bureau_fga', 'Cocody', [], false],
        ];

        foreach ($hopitaux as [$nom, $code, $commune, $services, $h24]) {
            [$lat, $lng] = $centres[$commune];
            $etab = Etablissement::updateOrCreate(['nom' => $nom], [
                'categorie_id' => Categorie::idPour($code),
                'commune_id' => $communes[$commune],
                'adresse' => "Adresse fictive, {$commune}",
                'lat' => $lat + 0.006,
                'lng' => $lng - 0.006,
                'tel1' => '+2252720000000',
                'h24' => $h24,
                'statut' => 'publie',
                'verifie_at' => now()->subDays(10),
            ]);
            $etab->syncServices($services);
            $etab->assurances()->sync([$assurances['CNAM (CMU)']]);
        }
    }
}
