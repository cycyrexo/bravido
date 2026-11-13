<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\Etablissement;
use App\Models\Garde;
use App\Models\JournalAdmin;
use App\Models\User;
use App\Services\Doublons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminTestHelpers;
use Tests\TestCase;

class DoublonsTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->preparer();
    }

    public function test_normalisation_des_noms_et_telephones(): void
    {
        $this->assertSame(Doublons::cleNom('Pharmacie Saint-Étienne'), Doublons::cleNom('PHCIE ST ETIENNE'));
        $this->assertSame('paix', Doublons::cleNom('Pharmacie de la Paix'));
        $this->assertSame(Doublons::cleTel('+2252720012603'), Doublons::cleTel('27 20 01 26 03'));
        $this->assertSame('', Doublons::cleTel('123'));
        $this->assertTrue(Doublons::estOrphelin('(nom à compléter — page 5)'));
        $this->assertFalse(Doublons::estOrphelin('Pharmacie Rivera'));
    }

    public function test_detection_par_nom_ou_telephone_et_exclusions(): void
    {
        $a = $this->etab('PHARMACIE DE LA PAIX');
        $b = $this->etab('Pharmacie de la Paix', [], 'pharmacie', 'Marcory');         // même nom, même ville
        $tel1 = $this->etab('Clinique Alpha', ['tel1' => '+2252720000001'], 'hopital_prive');
        $tel2 = $this->etab('Centre Beta', ['tel1' => '+2252720000001'], 'dentaire'); // même tel, autre nom/catégorie
        $autreCat = $this->etab('Optique de la Paix', [], 'optique');                 // même clé de nom mais catégorie différente
        $orph1 = $this->etab('(nom à compléter — page 5)');
        $orph2 = $this->etab('(nom à compléter — page 6)');
        $seul = $this->etab('Pharmacie Unique');

        $groupes = collect((new Doublons)->groupes());
        $ids = $groupes->map(fn ($g) => $g['ids'])->all();

        $this->assertContains([$a->id, $b->id], $ids);
        $this->assertContains([$tel1->id, $tel2->id], $ids);
        $flat = collect($ids)->flatten()->all();
        foreach ([$autreCat, $orph1, $orph2, $seul] as $e) {
            $this->assertNotContains($e->id, $flat);
        }
        $this->assertFalse($groupes->firstWhere('ids', [$a->id, $b->id])['meme_commune']);
    }

    public function test_pas_un_doublon_masque_definitivement_la_paire(): void
    {
        $a = $this->etab('Pharmacie Soleil');
        $b = $this->etab('PHARMACIE SOLEIL');

        $this->get('/admin/doublons')->assertOk()->assertSee('Pharmacie Soleil');
        $this->post('/admin/doublons/ignorer', ['ids' => [$b->id, $a->id]])->assertRedirect();

        $this->assertDatabaseHas('doublons_ignores', ['etablissement_a_id' => $a->id, 'etablissement_b_id' => $b->id]);
        $this->assertSame([], (new Doublons)->groupes());
        $this->get('/admin/doublons')->assertOk()->assertSee('Aucun doublon probable');
        $this->assertDatabaseHas('journal_admin', ['action' => 'doublons.ignorer']);
    }

    public function test_fusion_complete_les_champs_et_deplace_les_liens(): void
    {
        $maitre = $this->etab('PHARMACIE LUNE', ['tel1' => '+2252720001111']);
        $autre = $this->etab('Pharmacie Lune', [
            'tel1' => '+2252720001111', 'tel2' => '+2250707070707', 'email' => 'lune@exemple.ci', 'adresse' => 'Rue des Jardins',
            'lat' => 5.40, 'lng' => -3.99, 'position_precise' => true, 'h24' => true, 'tel_autres' => '+2250505050505',
        ]);
        $maitre->syncServices(['Garde de nuit']);
        $autre->syncServices(['Garde de nuit', 'Vaccination']);
        $mugefci = Assurance::where('nom', 'MUGEFCI')->value('id');
        $maitre->assurances()->attach(Assurance::where('nom', '!=', 'MUGEFCI')->value('id'));
        $autre->assurances()->attach($mugefci);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        DB::table('favoris')->insert([
            ['user_id' => $u1->id, 'etablissement_id' => $maitre->id, 'created_at' => now()],
            ['user_id' => $u1->id, 'etablissement_id' => $autre->id, 'created_at' => now()], // déjà favori du maître : pas de doublon
            ['user_id' => $u2->id, 'etablissement_id' => $autre->id, 'created_at' => now()],
        ]);
        Garde::create(['etablissement_id' => $autre->id, 'date_debut' => today(), 'date_fin' => today()->addDays(6)]);

        $this->post('/admin/doublons/fusionner', ['maitre_id' => $maitre->id, 'ids' => [$maitre->id, $autre->id]])->assertRedirect();

        $this->assertNull(Etablissement::find($autre->id));
        $m = $maitre->fresh();
        $this->assertSame('lune@exemple.ci', $m->email);
        $this->assertSame('Rue des Jardins', $m->adresse);
        $this->assertSame('+2250707070707', $m->tel2);
        $this->assertTrue($m->position_precise);
        $this->assertEqualsWithDelta(5.40, $m->lat, 0.0001);
        $this->assertTrue($m->h24);
        $this->assertSame('+2250505050505', $m->tel_autres);
        $this->assertEqualsWithDelta(0, count(array_diff(['Garde de nuit', 'Vaccination'], $m->services()->pluck('service')->all())), 0);
        $this->assertCount(2, $m->assurances);
        $this->assertSame(2, DB::table('favoris')->where('etablissement_id', $maitre->id)->count());
        $this->assertSame(1, Garde::where('etablissement_id', $maitre->id)->count());

        $j = JournalAdmin::where('action', 'etablissements.fusion')->firstOrFail();
        $this->assertNull($j->avant['maitre']['email']);
        $this->assertSame('lune@exemple.ci', $j->apres['maitre']['email']);
        $this->assertSame($autre->id, $j->avant['supprimes'][0]['id']);
        $this->assertSame(1, $j->apres['deplaces']['gardes']);
    }

    public function test_fusion_ne_duplique_pas_une_garde_qui_chevauche(): void
    {
        $maitre = $this->etab('Pharmacie A1');
        $autre = $this->etab('Pharmacie A1 bis');
        Garde::create(['etablissement_id' => $maitre->id, 'date_debut' => today(), 'date_fin' => today()->addDays(6)]);
        Garde::create(['etablissement_id' => $autre->id, 'date_debut' => today()->addDays(2), 'date_fin' => today()->addDays(9)]);

        $this->post('/admin/doublons/fusionner', ['maitre_id' => $maitre->id, 'ids' => [$autre->id]])->assertRedirect();

        $this->assertSame(1, Garde::where('etablissement_id', $maitre->id)->count());
    }

    public function test_fusion_est_transactionnelle(): void
    {
        $maitre = $this->etab('Pharmacie T1');
        $autre = $this->etab('Pharmacie T1 bis', ['email' => 'x@y.ci']);

        // Une fiche inexistante dans la liste : rien ne doit changer
        $this->post('/admin/doublons/fusionner', ['maitre_id' => $maitre->id, 'ids' => [$autre->id, 999999]])->assertSessionHasErrors('ids');

        $this->assertNotNull(Etablissement::find($autre->id));
        $this->assertNull($maitre->fresh()->email);
        $this->assertSame(0, JournalAdmin::where('action', 'etablissements.fusion')->count());
    }

    public function test_fusion_reservee_aux_administrateurs(): void
    {
        $mod = User::factory()->create();
        $mod->forceFill(['role' => 'moderateur', 'statut' => 'actif'])->save();
        $this->actingAs($mod);
        $a = $this->etab('Pharmacie M1');
        $b = $this->etab('Pharmacie M1 bis');

        $this->post('/admin/doublons/fusionner', ['maitre_id' => $a->id, 'ids' => [$b->id]])->assertForbidden();
        $this->get('/admin/doublons')->assertOk();
    }

    public function test_orphelins_listes_avec_page_pdf_et_fusion_vers_hopital(): void
    {
        $hopital = $this->etab('CHU de Cocody', [], 'hopital_public');
        $orph = $this->etab('(nom à compléter — page 12)', ['source_ref' => 'guidsante-2026:p12:abcdef0123456789abcd', 'tel1' => '+2252722000000']);
        $orph->syncServices(['Radiologie', 'Urgences']);
        $this->etab('(nom à compléter — page 3)', ['source_ref' => 'guidsante-2026:p3:00000000000000000001']);

        $html = $this->get('/admin/doublons/orphelins')->assertOk()->getContent();
        $this->assertStringContainsString('PDF page 12', $html);
        $this->assertLessThan(strpos($html, 'PDF page 12'), strpos($html, 'PDF page 3'));
        $this->assertStringContainsString('Radiologie', $html);

        // Recherche de la cible : les orphelins n'y figurent pas
        $this->getJson('/admin/doublons/cibles?commune_id='.$orph->commune_id.'&q=CHU')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.nom', 'CHU de Cocody');

        $this->post('/admin/doublons/fusionner', ['maitre_id' => $hopital->id, 'ids' => [$orph->id]])->assertRedirect();

        $this->assertNull(Etablissement::find($orph->id));
        $this->assertEqualsCanonicalizing(['Radiologie', 'Urgences'], $hopital->fresh()->services()->pluck('service')->all());
        $this->assertSame('+2252722000000', $hopital->fresh()->tel1);
    }
}
