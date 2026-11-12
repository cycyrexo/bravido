<?php

namespace App\Console\Commands;

use App\Models\Categorie;
use App\Models\Commune;
use App\Models\Etablissement;
use App\Models\Ville;
use App\Support\Telephone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import de l'annuaire GUIDSANTE (CSV extrait du fichier Publisher).
 *
 *   php artisan bravido:importer-guidsante docs/donnees/etablissements.csv docs/donnees/localites.json --dry-run
 *
 * - Aucune valeur n'est inventée : pas de GPS dans la source -> position = centre de la commune / ville,
 *   position_precise = false.
 * - Les lignes douteuses (sans nom, sans catégorie, avec remarque d'extraction) sont importées en BROUILLON.
 * - Relançable : une ligne déjà importée (source_ref) n'est pas recréée ni écrasée.
 */
class ImporterGuidsante extends Command
{
    protected $signature = 'bravido:importer-guidsante
        {csv : Chemin du CSV (séparateur ;)}
        {localites : JSON des coordonnées [{ville, commune, lat, lng, region}]}
        {--dry-run : Analyse sans écrire en base}
        {--supprimer-demo : Supprime les établissements fictifs « DÉMO » et leurs gardes}';

    protected $description = 'Importe les établissements de l\'annuaire GUIDSANTE';

    private const SOURCE = 'guidsante-2026';

    /** Catégorie de l'extraction -> code BRAVIDO */
    private const CATEGORIES = [
        'pharmacie' => 'pharmacie',
        'chu' => 'hopital_public',
        'hopital' => 'hopital_public',
        'hopital_public' => 'hopital_public',
        'militaire_securite' => 'militaire',
        'centre_sante' => 'hopital_public',
        'clinique' => 'hopital_prive',
        'cabinet_centre_medical' => 'hopital_prive',
        'militaire' => 'militaire',
        'veterinaire' => 'veterinaire',
        'optique' => 'optique',
        'cabinet_dentaire' => 'dentaire',
        'dentaire' => 'dentaire',
        'imagerie' => 'imagerie',
        'laboratoire' => 'laboratoire',
    ];

    public function handle(): int
    {
        $csv = $this->argument('csv');
        $fichierLocalites = $this->argument('localites');
        if (! is_file($csv) || ! is_file($fichierLocalites)) {
            $this->error('Fichier CSV ou JSON introuvable.');

            return self::FAILURE;
        }
        $simulation = (bool) $this->option('dry-run');

        $coords = $this->chargerLocalites($fichierLocalites);
        $lignes = $this->lireCsv($csv);
        $this->info(count($lignes).' lignes lues.');

        $stats = ['publies' => 0, 'brouillons' => 0, 'deja_importes' => 0, 'ignores' => 0];
        $parCategorie = [];
        $ignores = [];

        $executer = function () use ($lignes, $coords, $simulation, &$stats, &$parCategorie, &$ignores) {
            if (! $simulation) {
                $this->preparerCategories();
                if ($this->option('supprimer-demo')) {
                    $n = Etablissement::where('nom', 'like', 'DÉMO %')->delete();
                    $this->info("Établissements DÉMO supprimés : {$n}");
                }
            }
            $categories = Categorie::pluck('id', 'code');
            $communes = [];

            foreach ($lignes as $i => $l) {
                $ville = trim($l['ville'] ?? '');
                $communeNom = trim($l['commune'] ?? '') ?: $ville;
                $nom = $this->propre($l['nom'] ?? '', 200);
                $code = self::CATEGORIES[Str::lower(trim($l['categorie_proposee'] ?? ''))] ?? null;

                if ($ville === '') {
                    $stats['ignores']++;
                    $ignores[] = 'Ligne '.($i + 2).' : ville absente';

                    continue;
                }
                $position = $coords[$this->cle($ville, $communeNom)] ?? $coords[$this->cle($ville, $ville)] ?? null;
                if (! $position) {
                    $stats['ignores']++;
                    $ignores[] = 'Ligne '.($i + 2)." : coordonnées inconnues pour {$ville} / {$communeNom}";

                    continue;
                }

                $ref = self::SOURCE.':p'.($l['page'] ?? '0').':'.substr(sha1(($l['ligne_source'] ?? '').'|'.$nom.'|'.$ville.'|'.$communeNom), 0, 20);
                $douteux = $nom === '' || $code === null || trim($l['remarque'] ?? '') !== '';
                $statut = $douteux ? 'brouillon' : 'publie';
                $codeFinal = $code ?? 'a_classer';
                $parCategorie[$codeFinal][$statut] = ($parCategorie[$codeFinal][$statut] ?? 0) + 1;

                if ($simulation) {
                    $stats[$statut === 'publie' ? 'publies' : 'brouillons']++;

                    continue;
                }

                if (Etablissement::where('source_ref', $ref)->exists()) {
                    $stats['deja_importes']++;

                    continue;
                }

                $cleCommune = $this->cle($ville, $communeNom);
                $communes[$cleCommune] ??= $this->commune($ville, $communeNom, $position, $coords[$this->cle($ville, $ville)] ?? $position);

                [$tel1, $tel2, $autres] = $this->telephones($l['telephones'] ?? '');
                $services = $this->services($l['services'] ?? '');
                $h24 = (bool) preg_match('/24\s*h\s*\/?\s*24|24\s*\/\s*24/iu', ($l['horaires'] ?? '').' '.($l['services'] ?? ''));

                $etab = new Etablissement([
                    'categorie_id' => $categories[$codeFinal],
                    'nom' => $nom !== '' ? $nom : '(nom à compléter — page '.($l['page'] ?? '?').')',
                    'commune_id' => $communes[$cleCommune]->id,
                    'adresse' => $this->propre(str_replace(' ; ', ', ', $l['adresse'] ?? ''), 255) ?: null,
                    'lat' => $position['lat'],
                    'lng' => $position['lng'],
                    'tel1' => $tel1,
                    'tel2' => $tel2,
                    'h24' => $h24,
                    'statut' => $statut,
                ]);
                $etab->position_precise = false;
                $etab->tel_autres = $autres;
                $etab->email = filter_var(trim($l['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null;
                $etab->site_web = $this->propre($l['site_web'] ?? '', 255) ?: null;
                $etab->responsable_nom = $this->propre($l['responsable'] ?? '', 150) ?: null;
                $etab->description = trim($l['remarque'] ?? '') !== '' ? 'À vérifier (import) : '.$this->propre($l['remarque'], 500) : null;
                $etab->source = self::SOURCE;
                $etab->source_ref = $ref;
                $etab->save();
                $etab->syncServices($services);

                $stats[$statut === 'publie' ? 'publies' : 'brouillons']++;
            }
        };

        $simulation ? $executer() : DB::transaction($executer);

        if (! $simulation) {
            Cache::forget('api_config');
        }

        $this->newLine();
        $this->info($simulation ? 'SIMULATION (rien n\'a été écrit)' : 'IMPORT TERMINÉ');
        $this->table(['Publiés', 'Brouillons (à vérifier)', 'Déjà importés', 'Ignorés'], [array_values($stats)]);
        ksort($parCategorie);
        $this->table(['Catégorie', 'Publiés', 'Brouillons'], array_map(
            fn ($code, $n) => [$code, $n['publie'] ?? 0, $n['brouillon'] ?? 0],
            array_keys($parCategorie), $parCategorie
        ));
        foreach (array_slice($ignores, 0, 30) as $message) {
            $this->warn($message);
        }

        return self::SUCCESS;
    }

    /** Catégories nécessaires à l'annuaire (créées ou activées). */
    private function preparerCategories(): void
    {
        $ordre = (int) Categorie::max('ordre');
        foreach ([
            ['imagerie', 'Imagerie médicale', true],
            ['laboratoire', 'Laboratoires d\'analyses', true],
            ['a_classer', 'À classer', false], // jamais affichée dans l'app
        ] as [$code, $libelle, $actif]) {
            Categorie::firstOrCreate(['code' => $code], ['libelle' => $libelle, 'module' => 'soins', 'ordre' => ++$ordre, 'actif' => $actif]);
        }
        Categorie::whereIn('code', ['veterinaire', 'optique', 'dentaire'])->update(['actif' => true]);
    }

    private function commune(string $ville, string $commune, array $position, array $positionVille): Commune
    {
        $v = Ville::firstOrNew(['nom' => $ville]);
        if (! $v->exists || $v->lat === null) {
            $v->lat = $positionVille['lat'];
            $v->lng = $positionVille['lng'];
            $v->region = $v->region ?? ($positionVille['region'] ?? null);
        }
        $v->actif = true;
        $v->save();

        $c = Commune::firstOrNew(['ville_id' => $v->id, 'nom' => $commune]);
        $c->lat = $position['lat'];
        $c->lng = $position['lng'];
        $c->save();

        return $c;
    }

    /** @return array<string, array{lat: float, lng: float, region: ?string}> */
    private function chargerLocalites(string $fichier): array
    {
        $coords = [];
        foreach (json_decode(file_get_contents($fichier), true, flags: JSON_THROW_ON_ERROR) as $l) {
            if (! isset($l['lat'], $l['lng'])) {
                continue;
            }
            $coords[$this->cle($l['ville'], $l['commune'] ?? $l['ville'])] = [
                'lat' => (float) $l['lat'], 'lng' => (float) $l['lng'], 'region' => $l['region'] ?? null,
            ];
        }

        return $coords;
    }

    private function cle(string $ville, string $commune): string
    {
        return Str::of($ville.'|'.$commune)->ascii()->lower()->replaceMatches('/[^a-z0-9|]+/', '')->value();
    }

    /** @return array<int, array<string, string>> */
    private function lireCsv(string $chemin): array
    {
        $f = fopen($chemin, 'r');
        $entetes = fgetcsv($f, null, ';', '"', '');
        $entetes[0] = preg_replace('/^\xEF\xBB\xBF/', '', $entetes[0]);
        $lignes = [];
        while (($ligne = fgetcsv($f, null, ';', '"', '')) !== false) {
            if ($ligne === [null]) {
                continue;
            }
            $lignes[] = array_combine($entetes, array_pad(array_slice($ligne, 0, count($entetes)), count($entetes), ''));
        }
        fclose($f);

        return $lignes;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} tel1, tel2, autres */
    private function telephones(string $brut): array
    {
        $numeros = [];
        foreach (preg_split('#\s*/\s*#', $brut) ?: [] as $n) {
            $n = trim($n);
            // Les numéros irréguliers de la source sont marqués « (?) » : on ne les publie pas
            if ($n === '' || str_contains($n, '?')) {
                continue;
            }
            $normalise = Telephone::normaliser($n);
            if ($normalise && ! in_array($normalise, $numeros, true)) {
                $numeros[] = $normalise;
            }
        }

        return [$numeros[0] ?? null, $numeros[1] ?? null, implode(' / ', array_slice($numeros, 2, 4)) ?: null];
    }

    /** @return string[] */
    private function services(string $brut): array
    {
        $services = [];
        foreach (explode(';', $brut) as $s) {
            $s = trim(preg_replace('/^[\s·•\.\-–,:]+|[\s,;\.]+$/u', '', $s) ?? '');
            if (mb_strlen($s) >= 3 && mb_strlen($s) <= 100) {
                $services[mb_strtolower($s)] = $s;
            }
        }

        return array_slice(array_values($services), 0, 30);
    }

    private function propre(string $texte, int $max): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $texte) ?? ''), $max, '');
    }
}
