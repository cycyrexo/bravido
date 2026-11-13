<?php

namespace Tests\Unit;

use App\Models\Commune;
use App\Models\Etablissement;
use App\Services\Garde\PharmacieMatcher;
use PHPUnit\Framework\TestCase;

class PharmacieMatcherTest extends TestCase
{
    private PharmacieMatcher $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new PharmacieMatcher;
    }

    private function base(array $defs): array
    {
        $liste = collect($defs)->map(function (array $d, int $i) {
            $e = new Etablissement(['nom' => $d[0], 'tel1' => $d[2] ?? null]);
            $e->id = $i + 1;
            $e->setRelation('commune', new Commune(['nom' => $d[1]]));

            return $e;
        });

        return $this->m->preparer($liste);
    }

    public function test_normalisation_accents_et_abreviations(): void
    {
        $this->assertSame('pharmacie saint jean', PharmacieMatcher::normaliser('PHCIE  Saint-Jean'));
        $this->assertSame('pharmacie de l ete', PharmacieMatcher::normaliser('Pharmacie de l\'Été'));
        $this->assertSame(['paix'], PharmacieMatcher::mots('Pharmacie de la Paix'));
        $this->assertSame(['lagunes', '2'], PharmacieMatcher::mots('Phcie des Lagunes 2'));
    }

    public function test_telephones_extraits_sur_huit_chiffres(): void
    {
        $this->assertSame(['22445566'], PharmacieMatcher::telephones('Tel : 27 22 44 55 66'));
        $this->assertSame(['07123456', '22445566'], PharmacieMatcher::telephones('+225 07 12 34 56 / 00225 22 44 55 66'));
        $this->assertSame([], PharmacieMatcher::telephones('Cocody 2025'));
    }

    public function test_paix_contre_paix_cocody(): void
    {
        $base = $this->base([
            ['PHARMACIE DE LA PAIX COCODY', 'Cocody'],
            ['Pharmacie des Lagunes', 'Cocody'],
        ]);
        $r = $this->m->classer('Pharmacie de la Paix', $base);

        $this->assertSame('ok', $r['statut']);
        $this->assertSame(1, $r['candidats'][0]['id']);
        $this->assertGreaterThanOrEqual(0.85, $r['score']);
    }

    public function test_abreviation_phcie_et_faute_de_frappe(): void
    {
        $base = $this->base([['Pharmacie Saint Michel', 'Marcory'], ['Pharmacie Les Lagunes', 'Cocody']]);

        $this->assertSame(1, $this->m->classer('PHCIE ST MICHEL', $base)['candidats'][0]['id'] ?? null, 'abréviation de « Saint » non gérée : à confirmer au minimum');
        $r = $this->m->classer('Phcie Les Lagunnes', $base);
        $this->assertSame('ok', $r['statut']);
        $this->assertSame(2, $r['candidats'][0]['id']);
    }

    public function test_accents_ignores(): void
    {
        $base = $this->base([['Pharmacie de l\'Étoile', 'Yopougon']]);
        $this->assertSame('ok', $this->m->classer('PHARMACIE DE L ETOILE', $base)['statut']);
    }

    public function test_ligne_avec_adresse_et_telephone(): void
    {
        $base = $this->base([
            ['Pharmacie de la Paix', 'Cocody', '+22522445566'],
            ['Pharmacie Paix Saint Jean', 'Cocody', '+22522000000'],
        ]);
        $r = $this->m->classer('3. PHARMACIE DE LA PAIX - Riviera 2 - Tel 22 44 55 66', $base);

        $this->assertSame('ok', $r['statut']);
        $this->assertSame(1, $r['candidats'][0]['id']);
    }

    public function test_homonymes_ambigus_restent_a_verifier(): void
    {
        $base = $this->base([['Pharmacie du Centre', 'Cocody'], ['Pharmacie du Centre', 'Marcory']]);
        $r = $this->m->classer('Pharmacie du Centre', $base);

        $this->assertNotSame('ok', $r['statut'], 'deux pharmacies identiques : jamais de choix automatique');
        $this->assertCount(2, $r['candidats']);

        // La commune départage
        $r2 = $this->m->classer('Pharmacie du Centre - Marcory', $base);
        $this->assertSame('ok', $r2['statut']);
        $this->assertSame(2, $r2['candidats'][0]['id']);
    }

    public function test_numero_de_serie_different_n_est_pas_automatique(): void
    {
        $base = $this->base([['Pharmacie Les Lagunes 2', 'Cocody'], ['Pharmacie Les Lagunes 3', 'Cocody']]);
        $r = $this->m->classer('Pharmacie Les Lagunes 2', $base);

        $this->assertSame('ok', $r['statut']);
        $this->assertSame(1, $r['candidats'][0]['id']);
        $this->assertNotSame('ok', $this->m->classer('Pharmacie Les Lagunes', $base)['statut']);
    }

    public function test_ligne_sans_rapport_est_rejetee(): void
    {
        $base = $this->base([['Pharmacie de la Paix', 'Cocody']]);
        $r = $this->m->classer('PROGRAMME DE GARDE DU 14 AU 20 NOVEMBRE', $base);

        $this->assertSame('erreur', $r['statut']);
        $this->assertSame([], $r['candidats']);
    }
}
