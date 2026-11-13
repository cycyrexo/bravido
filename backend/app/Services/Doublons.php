<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\Garde;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Détection et fusion des doublons d'établissements (annuaire importé de PDF).
 * Deux fiches sont des doublons probables si elles sont dans la même ville ET
 * (nom normalisé identique [même catégorie] OU même téléphone principal).
 */
class Doublons
{
    /** Mots sans valeur discriminante, retirés avant comparaison des noms. */
    private const MOTS_VIDES = [
        'pharmacie', 'pharmacies', 'phcie', 'pharm', 'pharma', 'cabinet', 'clinique', 'centre', 'hopital', 'hosp',
        'laboratoire', 'labo', 'optique', 'dentaire', 'medical', 'dr', 'docteur',
        'de', 'du', 'la', 'le', 'les', 'des', 'd', 'l', 'et',
    ];

    /** Champs texte complétés dans la fiche conservée quand elle les a vides. */
    private const CHAMPS_A_COMPLETER = ['adresse', 'repere', 'description', 'tel1', 'tel2', 'email', 'site_web', 'responsable_nom', 'horaires', 'responsable_id'];

    public static function cleNom(string $nom): string
    {
        $mots = preg_split('/\s+/', Str::of($nom)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $mots = array_map(fn ($m) => match ($m) {
            'st' => 'saint', 'ste' => 'sainte', default => $m,
        }, $mots);
        $mots = array_values(array_diff($mots, self::MOTS_VIDES));

        return implode(' ', $mots);
    }

    /** Téléphone comparable : chiffres seuls, sans l'indicatif 225. */
    public static function cleTel(?string $tel): string
    {
        $chiffres = preg_replace('/\D+/', '', (string) $tel);
        if (str_starts_with($chiffres, '225') && strlen($chiffres) > 10) {
            $chiffres = substr($chiffres, 3);
        }

        return strlen($chiffres) >= 8 ? $chiffres : '';
    }

    public static function estOrphelin(string $nom): bool
    {
        return str_starts_with($nom, '(nom ') && Str::contains(Str::ascii($nom), 'completer');
    }

    /** Numéro de page du PDF d'origine d'un brouillon orphelin (source_ref « …:p5:… » ou nom « … page 5) »). */
    public static function pagePdf(Etablissement $e): ?int
    {
        if (preg_match('/:p(\d+):/', (string) $e->source_ref, $m) || preg_match('/page\s+(\d+)/i', $e->nom, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return list<array{ids: list<int>, motifs: list<string>, meme_commune: bool, ville_id: int}>
     */
    public function groupes(?int $villeId = null): array
    {
        $lignes = Etablissement::query()
            ->join('communes', 'communes.id', '=', 'etablissements.commune_id')
            ->join('categories', 'categories.id', '=', 'etablissements.categorie_id')
            ->when($villeId, fn ($q) => $q->where('communes.ville_id', $villeId))
            ->get(['etablissements.id', 'etablissements.nom', 'etablissements.tel1', 'etablissements.commune_id',
                'etablissements.categorie_id', 'communes.ville_id', 'categories.code as categorie_code']);

        $ignores = DB::table('doublons_ignores')->get(['etablissement_a_id', 'etablissement_b_id'])
            ->mapWithKeys(fn ($r) => [$r->etablissement_a_id.'-'.$r->etablissement_b_id => true])->all();

        $parNom = [];
        $parTel = [];
        foreach ($lignes as $l) {
            if (self::estOrphelin($l->nom)) {
                continue; // les brouillons orphelins ont leur propre outil
            }
            $nom = self::cleNom($l->nom);
            if (strlen($nom) >= 3) {
                // « à classer » (catégorie inactive) peut doubler n'importe quelle catégorie : clé de catégorie neutre
                $parNom[$l->ville_id.'|'.$nom][] = $l;
            }
            $tel = self::cleTel($l->tel1);
            if ($tel !== '') {
                $parTel[$l->ville_id.'|'.$tel][] = $l;
            }
        }

        // Arêtes : paire (a<b) => motifs
        $aretes = [];
        $lier = function (array $membres, string $motif) use (&$aretes, $ignores) {
            $n = count($membres);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$a, $b] = $membres[$i]->id < $membres[$j]->id ? [$membres[$i], $membres[$j]] : [$membres[$j], $membres[$i]];
                    if (isset($ignores[$a->id.'-'.$b->id])) {
                        continue;
                    }
                    // Même nom : même catégorie exigée (sauf « à classer »)
                    if ($motif === 'nom' && $a->categorie_id !== $b->categorie_id
                        && $a->categorie_code !== 'a_classer' && $b->categorie_code !== 'a_classer') {
                        continue;
                    }
                    $aretes[$a->id.'-'.$b->id]['motifs'][$motif] = true;
                    $aretes[$a->id.'-'.$b->id]['paire'] = [$a, $b];
                }
            }
        };
        foreach ($parNom as $membres) {
            count($membres) > 1 && $lier($membres, 'nom');
        }
        foreach ($parTel as $membres) {
            count($membres) > 1 && $lier($membres, 'tel');
        }

        // Composantes connexes (union-find)
        $parent = [];
        $trouver = function (int $x) use (&$parent, &$trouver) {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $trouver($parent[$x]));
        };
        foreach ($aretes as $e) {
            [$a, $b] = $e['paire'];
            $parent[$trouver($a->id)] = $trouver($b->id);
        }

        $groupes = [];
        $infos = [];
        $motifs = [];
        foreach ($aretes as $e) {
            [$a, $b] = $e['paire'];
            $racine = $trouver($a->id);
            foreach ([$a, $b] as $x) {
                $groupes[$racine][$x->id] = true;
                $infos[$x->id] = $x;
            }
            foreach (array_keys($e['motifs']) as $m) {
                $motifs[$racine][$m] = true;
            }
        }

        $resultat = [];
        foreach ($groupes as $racine => $ids) {
            $ids = array_keys($ids);
            sort($ids);
            $communes = array_unique(array_map(fn ($id) => $infos[$id]->commune_id, $ids));
            $resultat[] = [
                'ids' => $ids,
                'motifs' => array_keys($motifs[$racine]),
                'meme_commune' => count($communes) === 1,
                'ville_id' => $infos[$ids[0]]->ville_id,
            ];
        }

        // Les plus probables d'abord : même commune, double motif, puis nombre de fiches
        usort($resultat, fn ($x, $y) => [$y['meme_commune'], count($y['motifs']), $x['ids'][0]] <=> [$x['meme_commune'], count($x['motifs']), $y['ids'][0]]);

        return $resultat;
    }

    /** Note de complétude : sert à présélectionner la fiche à conserver. */
    public static function score(Etablissement $e): int
    {
        $score = $e->position_precise ? 20 : 0;
        $score += $e->statut === 'publie' ? 10 : 0;
        $score += $e->verifie_at ? 5 : 0;
        foreach (['adresse', 'repere', 'description', 'tel1', 'tel2', 'tel_autres', 'email', 'site_web', 'responsable_nom', 'horaires'] as $c) {
            $score += filled($e->{$c}) ? 1 : 0;
        }

        return $score + ($e->services_count ?? $e->services->count()) + ($e->gardes_count ?? 0) * 2;
    }

    /** @param  list<int>  $ids */
    public function ignorer(array $ids, ?int $adminId): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $n = 0;
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $n += DB::table('doublons_ignores')->insertOrIgnore([
                    'etablissement_a_id' => $ids[$i], 'etablissement_b_id' => $ids[$j],
                    'created_by' => $adminId, 'created_at' => now(),
                ]);
            }
        }

        return $n;
    }

    /**
     * Fusionne $autresIds dans $maitreId (transaction unique).
     *
     * @param  list<int>  $autresIds
     * @return array{avant: array, apres: array, maitre: Etablissement}
     */
    public function fusionner(int $maitreId, array $autresIds): array
    {
        $autresIds = array_values(array_diff(array_unique(array_map('intval', $autresIds)), [$maitreId]));
        if ($autresIds === []) {
            throw ValidationException::withMessages(['ids' => 'Sélectionnez au moins une autre fiche à fusionner.']);
        }

        return DB::transaction(function () use ($maitreId, $autresIds) {
            $tous = Etablissement::whereIn('id', [$maitreId, ...$autresIds])->lockForUpdate()->get()->keyBy('id');
            if ($tous->count() !== count($autresIds) + 1) {
                throw ValidationException::withMessages(['ids' => 'Une des fiches a déjà été supprimée ou fusionnée : rechargez la page.']);
            }
            $maitre = $tous[$maitreId];
            $autres = $tous->except($maitreId)->values();

            $avant = [
                'maitre' => $this->instantane($maitre),
                'supprimes' => $autres->map(fn ($e) => $this->instantane($e))->all(),
            ];

            $this->completerChamps($maitre, $autres);
            $maitre->save();

            $deplaces = $this->deplacerLiens($maitre, $autres);

            Etablissement::whereIn('id', $autresIds)->delete();

            $maitre = $maitre->fresh();
            $apres = ['maitre' => $this->instantane($maitre), 'deplaces' => $deplaces];

            return ['avant' => $avant, 'apres' => $apres, 'maitre' => $maitre];
        });
    }

    private function completerChamps(Etablissement $maitre, Collection $autres): void
    {
        foreach ($autres as $a) {
            foreach (self::CHAMPS_A_COMPLETER as $c) {
                if (blank($maitre->{$c}) && filled($a->{$c})) {
                    $maitre->{$c} = $a->{$c};
                }
            }
            $maitre->h24 = $maitre->h24 || $a->h24;
            $maitre->premium = $maitre->premium || $a->premium;
            // La seule position GPS fiable l'emporte sur un centre de commune
            if (! $maitre->position_precise && $a->position_precise) {
                $maitre->lat = $a->lat;
                $maitre->lng = $a->lng;
                $maitre->position_precise = true;
                $maitre->verifie_at = $a->verifie_at ?? $maitre->verifie_at;
            }
            if ($maitre->verifie_at === null && $a->verifie_at !== null) {
                $maitre->verifie_at = $a->verifie_at;
            }
        }

        // Numéros supplémentaires : conservés dans tel_autres (séparés par « / »), sans doublon
        $connus = [];
        foreach ([$maitre->tel1, $maitre->tel2, ...explode('/', (string) $maitre->tel_autres)] as $t) {
            if (self::cleTel(trim((string) $t)) !== '') {
                $connus[self::cleTel(trim((string) $t))] = true;
            }
        }
        $extras = array_filter(array_map('trim', explode('/', (string) $maitre->tel_autres)));
        foreach ($autres as $a) {
            foreach ([$a->tel1, $a->tel2, ...explode('/', (string) $a->tel_autres)] as $t) {
                $t = trim((string) $t);
                $cle = self::cleTel($t);
                if ($cle !== '' && ! isset($connus[$cle])) {
                    $connus[$cle] = true;
                    $extras[] = $t;
                }
            }
        }
        $maitre->tel_autres = $extras === [] ? null : Str::limit(implode(' / ', $extras), 255, '');
    }

    /** @return array<string, int> */
    private function deplacerLiens(Etablissement $maitre, Collection $autres): array
    {
        $ids = $autres->pluck('id')->all();
        $n = ['services' => 0, 'assurances' => 0, 'favoris' => 0, 'gardes' => 0, 'fiches' => 0, 'messages' => 0];

        // Services : union
        foreach (DB::table('etab_services')->whereIn('etablissement_id', $ids)->pluck('service')->unique() as $service) {
            $n['services'] += DB::table('etab_services')->insertOrIgnore(['etablissement_id' => $maitre->id, 'service' => $service]);
        }
        // Assurances : union
        foreach (DB::table('etab_assurances')->whereIn('etablissement_id', $ids)->pluck('assurance_id')->unique() as $assurance) {
            $n['assurances'] += DB::table('etab_assurances')->insertOrIgnore(['etablissement_id' => $maitre->id, 'assurance_id' => $assurance]);
        }
        // Favoris : les utilisateurs qui avaient une des fiches suivent la fiche conservée
        foreach (DB::table('favoris')->whereIn('etablissement_id', $ids)->get() as $f) {
            $n['favoris'] += DB::table('favoris')->insertOrIgnore([
                'user_id' => $f->user_id, 'etablissement_id' => $maitre->id, 'created_at' => $f->created_at,
            ]);
        }
        // Gardes : déplacées, sauf si la fiche conservée est déjà de garde sur une période qui chevauche
        foreach (Garde::whereIn('etablissement_id', $ids)->get() as $g) {
            $existe = Garde::where('etablissement_id', $maitre->id)
                ->chevauche($g->date_debut->toDateString(), $g->date_fin->toDateString())->exists();
            if ($existe) {
                $g->delete();
            } else {
                $g->update(['etablissement_id' => $maitre->id]);
                $n['gardes']++;
            }
        }
        $n['fiches'] = DB::table('fiches')->whereIn('etablissement_id', $ids)->update(['etablissement_id' => $maitre->id]);
        $n['messages'] = DB::table('messages_support')->whereIn('etablissement_id', $ids)->update(['etablissement_id' => $maitre->id]);

        return $n;
    }

    /** Photo complète d'une fiche pour le journal (avant/après). */
    private function instantane(Etablissement $e): array
    {
        return $e->only([
            'id', 'categorie_id', 'nom', 'commune_id', 'adresse', 'repere', 'description', 'lat', 'lng', 'position_precise',
            'tel1', 'tel2', 'tel_autres', 'email', 'site_web', 'responsable_nom', 'horaires', 'h24', 'statut', 'premium',
            'responsable_id', 'source', 'source_ref',
        ]) + [
            'verifie_at' => $e->verifie_at?->toDateTimeString(),
            'services' => DB::table('etab_services')->where('etablissement_id', $e->id)->orderBy('service')->pluck('service')->all(),
            'assurances' => DB::table('etab_assurances')->where('etablissement_id', $e->id)->pluck('assurance_id')->all(),
            'nb_favoris' => DB::table('favoris')->where('etablissement_id', $e->id)->count(),
            'nb_gardes' => DB::table('gardes')->where('etablissement_id', $e->id)->count(),
        ];
    }
}
