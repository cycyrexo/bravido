<?php

namespace Database\Seeders;

use App\Models\Assurance;
use App\Models\Categorie;
use App\Models\Commune;
use App\Models\NumeroUrgence;
use App\Models\Ville;
use Illuminate\Database\Seeder;

/**
 * Référentiels. Idempotent : peut être relancé sans créer de doublons.
 * Les numéros et la liste des assureurs sont À VÉRIFIER avant la mise en production.
 */
class ReferentielSeeder extends Seeder
{
    public function run(): void
    {
        $villes = [
            'Abidjan' => ['region' => 'District autonome d\'Abidjan', 'lat' => 5.3600, 'lng' => -4.0083, 'communes' => [
                'Abobo', 'Adjamé', 'Anyama', 'Attécoubé', 'Bingerville', 'Cocody', 'Koumassi',
                'Marcory', 'Plateau', 'Port-Bouët', 'Songon', 'Treichville', 'Yopougon',
            ]],
            // Phase 2 : villes créées dès maintenant, désactivées dans l'app
            'Bouaké' => ['region' => 'Gbêkê', 'lat' => 7.6899, 'lng' => -5.0303, 'communes' => ['Bouaké'], 'actif' => false],
            'Yamoussoukro' => ['region' => 'District autonome de Yamoussoukro', 'lat' => 6.8276, 'lng' => -5.2893, 'communes' => ['Yamoussoukro'], 'actif' => false],
            'San-Pédro' => ['region' => 'San-Pédro', 'lat' => 4.7485, 'lng' => -6.6363, 'communes' => ['San-Pédro'], 'actif' => false],
            'Korhogo' => ['region' => 'Poro', 'lat' => 9.4580, 'lng' => -5.6296, 'communes' => ['Korhogo'], 'actif' => false],
            'Daloa' => ['region' => 'Haut-Sassandra', 'lat' => 6.8774, 'lng' => -6.4502, 'communes' => ['Daloa'], 'actif' => false],
        ];

        foreach ($villes as $nom => $v) {
            $ville = Ville::updateOrCreate(['nom' => $nom], [
                'region' => $v['region'], 'lat' => $v['lat'], 'lng' => $v['lng'], 'actif' => $v['actif'] ?? true,
            ]);
            foreach ($v['communes'] as $commune) {
                Commune::firstOrCreate(['ville_id' => $ville->id, 'nom' => $commune]);
            }
        }

        $categories = [
            ['pharmacie', 'Pharmacies', 'pharmacie', true],
            ['hopital_public', 'Établissements publics', 'soins', true],
            ['hopital_prive', 'Établissements privés', 'soins', true],
            ['militaire', 'Établissements militaires', 'soins', true],
            ['veterinaire', 'Cliniques vétérinaires', 'soins', false],   // V2
            ['optique', 'Optique et lunetterie', 'soins', false],        // V2
            ['dentaire', 'Cabinets dentaires', 'soins', false],          // V2
            ['centre_secours', 'Sapeurs-pompiers (GSPM / ONPC)', 'urgence', true],
            ['bureau_fga', 'Accident de la route : prise en charge FGA', 'urgence', true],
        ];
        foreach ($categories as $i => [$code, $libelle, $module, $actif]) {
            Categorie::updateOrCreate(['code' => $code], [
                'libelle' => $libelle, 'module' => $module, 'ordre' => $i + 1, 'actif' => $actif,
            ]);
        }

        // Numéros nationaux (URG-05) — à confirmer auprès des autorités
        $numeros = [
            ['SAMU', '185', 'Urgences médicales'],
            ['Sapeurs-pompiers (GSPM / ONPC)', '180', 'Incendie, accident, secours à personne'],
            ['Police secours', '110', null],
            ['Police secours', '111', null],
            ['Police secours', '170', null],
        ];
        foreach ($numeros as $i => [$libelle, $numero, $description]) {
            NumeroUrgence::updateOrCreate(['numero' => $numero], [
                'libelle' => $libelle, 'description' => $description, 'ordre' => $i + 1, 'actif' => true,
            ]);
        }

        // Liste normalisée d'assurances — à compléter avec les établissements et assureurs
        $assurances = [
            ['CNAM (CMU)', 'publique'],
            ['MUGEFCI', 'mutuelle'],
            ['NSIA Assurances', 'privee'],
            ['SUNU Assurances', 'privee'],
            ['Allianz Côte d\'Ivoire', 'privee'],
            ['AXA Côte d\'Ivoire', 'privee'],
            ['Sanlam Côte d\'Ivoire', 'privee'],
            ['Atlantique Assurances', 'privee'],
        ];
        foreach ($assurances as [$nom, $type]) {
            Assurance::updateOrCreate(['nom' => $nom], ['type' => $type, 'actif' => true]);
        }
    }
}
