<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\Garde;
use App\Models\ImportLot;
use App\Models\Ville;
use App\Services\Garde\PharmacieMatcher;
use Carbon\CarbonInterface;
use DateTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * BO-03 : import CSV / Excel du programme de garde.
 * Colonnes reconnues : id (facultatif), nom, commune, date_debut, date_fin (facultatives :
 * à défaut, la période saisie dans le formulaire s'applique).
 * Étapes : analyse -> aperçu (lot "apercu") -> publication (création des gardes).
 */
class ImportGardes
{
    private const ALIAS = [
        'pharmacie' => 'nom', 'nom_pharmacie' => 'nom', 'denomination' => 'nom',
        'identifiant' => 'id',
        'debut' => 'date_debut', 'du' => 'date_debut',
        'fin' => 'date_fin', 'au' => 'date_fin',
    ];

    public function analyser(UploadedFile $fichier, Ville $ville, CarbonInterface $debut, CarbonInterface $fin, ?int $auteurId): ImportLot
    {
        $lignes = $this->lire($fichier);

        $pharmacies = Etablissement::query()
            ->deCategorie('pharmacie')
            ->dansVille($ville->id)
            ->with('commune:id,nom')
            ->get(['id', 'nom', 'commune_id']);
        $parId = $pharmacies->keyBy('id');
        $parNom = $pharmacies->groupBy(fn (Etablissement $e) => $this->cle($e->nom));

        $resultats = [];
        $vus = [];

        foreach ($lignes as $i => $ligne) {
            $numero = $i + 2; // ligne 1 = en-têtes
            $nom = trim((string) ($ligne['nom'] ?? ''));
            $commune = trim((string) ($ligne['commune'] ?? ''));
            $id = trim((string) ($ligne['id'] ?? ''));

            if ($nom === '' && $id === '') {
                continue;
            }

            $r = [
                'ligne' => $numero, 'nom' => $nom, 'commune' => $commune,
                'etablissement_id' => null, 'etablissement_nom' => null,
                'date_debut' => null, 'date_fin' => null,
                'statut' => 'ok', 'message' => null,
            ];

            $d = $this->date($ligne['date_debut'] ?? null);
            $f = $this->date($ligne['date_fin'] ?? null);
            if ($d === false || $f === false) {
                $resultats[] = $this->erreur($r, 'Date illisible (formats acceptés : JJ/MM/AAAA ou AAAA-MM-JJ).');

                continue;
            }
            $r['date_debut'] = $d ?? $debut->toDateString();
            $r['date_fin'] = $f ?? $fin->toDateString();

            if ($r['date_fin'] < $r['date_debut']) {
                $resultats[] = $this->erreur($r, 'La date de fin précède la date de début.');

                continue;
            }

            [$etab, $message] = $this->trouver($id, $nom, $commune, $parId, $parNom);
            if (! $etab) {
                $resultats[] = $this->erreur($r, $message);

                continue;
            }

            $r['etablissement_id'] = $etab->id;
            $r['etablissement_nom'] = $etab->nom.' ('.$etab->commune->nom.')';

            if (isset($vus[$etab->id])) {
                $r['statut'] = 'doublon';
                $r['message'] = "En double avec la ligne {$vus[$etab->id]}.";
            } elseif (Garde::where('etablissement_id', $etab->id)->chevauche($r['date_debut'], $r['date_fin'])->exists()) {
                $r['statut'] = 'doublon';
                $r['message'] = 'Déjà de garde sur cette période : ligne ignorée.';
            } else {
                $vus[$etab->id] = $numero;
            }

            $resultats[] = $r;
        }

        if ($resultats === []) {
            throw ValidationException::withMessages(['fichier' => 'Le fichier ne contient aucune ligne exploitable.']);
        }

        $valides = count(array_filter($resultats, fn ($r) => $r['statut'] === 'ok'));

        return ImportLot::create([
            'ville_id' => $ville->id,
            'date_debut' => $debut->toDateString(),
            'date_fin' => $fin->toDateString(),
            'fichier_nom' => Str::limit($fichier->getClientOriginalName(), 250, ''),
            'statut' => 'apercu',
            'lignes' => $resultats,
            'nb_valides' => $valides,
            'nb_erreurs' => count($resultats) - $valides,
            'created_by' => $auteurId,
        ]);
    }

    /** Le texte brut est-il un CSV avec en-têtes (nom;commune;…) plutôt qu'une liste libre ? */
    public function ressembleACsv(string $texte): bool
    {
        $premiere = trim((string) strtok(ltrim($texte, "\xEF\xBB\xBF\r\n "), "\n"));
        $cellules = preg_split('/[;,\t]/', $premiere) ?: [];
        if (count($cellules) < 2) {
            return false;
        }
        $entetes = array_map(fn ($c) => $this->entete((string) $c), $cellules);

        return in_array('nom', $entetes, true) || in_array('id', $entetes, true);
    }

    /**
     * Texte libre (PDF, photo/OCR, collé) : une pharmacie par ligne. Rapprochement flou avec les
     * pharmacies de la ville ; seules les lignes sûres sont « ok », les autres attendent l'administrateur.
     */
    public function analyserTexte(string $texte, Ville $ville, CarbonInterface $debut, CarbonInterface $fin, ?int $auteurId, string $source): ImportLot
    {
        $pharmacies = Etablissement::query()
            ->deCategorie('pharmacie')
            ->dansVille($ville->id)
            ->with('commune:id,nom')
            ->get(['id', 'nom', 'commune_id', 'tel1', 'tel2', 'tel_autres']);

        $matcher = new PharmacieMatcher;
        $base = $matcher->preparer($pharmacies);

        $resultats = [];
        foreach (preg_split('/\n/', $texte) ?: [] as $i => $brut) {
            $ligne = trim(preg_replace('/^\s*(?:\d{1,3}\s*[.)]|[-•*·▪●■□])\s+/u', '', trim($brut)) ?? '');
            if (mb_strlen($ligne) < 3 || ! preg_match('/\pL{2}/u', $ligne)) {
                continue;
            }

            $c = $matcher->classer($ligne, $base);
            $top = $c['candidats'][0] ?? null;
            $candidats = array_map(fn ($x) => [
                'id' => $x['id'], 'nom' => $x['nom'], 'commune' => $x['commune'], 'score' => (int) round($x['score'] * 100),
            ], $c['candidats']);

            $r = [
                'ligne' => $i + 1, 'nom' => Str::limit($ligne, 250, ''), 'commune' => '',
                'etablissement_id' => null, 'etablissement_nom' => null,
                'date_debut' => $debut->toDateString(), 'date_fin' => $fin->toDateString(),
                'statut' => $c['statut'], 'message' => null,
                'score' => (int) round($c['score'] * 100), 'candidats' => $candidats,
                'confirme' => false, 'ignore' => false,
            ];

            if ($c['statut'] === 'ok') {
                $r['etablissement_id'] = $top['id'];
                $r['etablissement_nom'] = $top['nom'].' ('.$top['commune'].')';
            }
            // « a_verifier » : le meilleur candidat reste dans « candidats » mais la ligne n'est rattachée à rien
            // tant que l'administrateur n'a pas confirmé : elle ne peut pas être publiée par erreur.
            $r['message'] = match ($c['statut']) {
                'ok' => null,
                'a_verifier' => 'À confirmer : vérifiez la pharmacie proposée puis enregistrez.',
                default => $candidats
                    ? 'Correspondance trop incertaine : choisissez la pharmacie ou ignorez la ligne.'
                    : 'Aucune pharmacie reconnue : choisissez-la ou ignorez la ligne.',
            };

            $resultats[] = $r;
            if (count($resultats) >= 500) {
                break;
            }
        }

        if ($resultats === []) {
            throw ValidationException::withMessages(['fichier' => 'Aucune ligne exploitable dans ce texte.']);
        }

        $resultats = $this->controlerDoublons($resultats);
        $nbOk = count(array_filter($resultats, fn ($r) => $r['statut'] === 'ok'));

        return ImportLot::create([
            'ville_id' => $ville->id,
            'date_debut' => $debut->toDateString(),
            'date_fin' => $fin->toDateString(),
            'fichier_nom' => Str::limit($source, 250, ''),
            'statut' => 'apercu',
            'lignes' => $resultats,
            'nb_valides' => $nbOk,
            'nb_erreurs' => count($resultats) - $nbOk,
            'created_by' => $auteurId,
        ]);
    }

    /**
     * Applique les choix de l'administrateur (n° de ligne => id de pharmacie ; null ou '' = ignorer)
     * sur un lot en aperçu. Seul un choix explicite rend une ligne floue « ok » (hors doublon).
     *
     * @param  array<int|string, int|string|null>  $choix
     */
    public function appliquerChoix(ImportLot $lot, array $choix): ImportLot
    {
        abort_unless($lot->statut === 'apercu', 409, 'Ce lot a déjà été traité.');

        $valides = Etablissement::query()->deCategorie('pharmacie')->dansVille($lot->ville_id)
            ->with('commune:id,nom')->get(['id', 'nom', 'commune_id'])->keyBy('id');

        $lignes = $lot->lignes;
        foreach ($lignes as &$l) {
            if (! array_key_exists($l['ligne'], $choix)) {
                continue;
            }
            $id = $choix[$l['ligne']];
            if ($id === null || $id === '') {
                $l = array_merge($l, ['etablissement_id' => null, 'etablissement_nom' => null, 'statut' => 'erreur',
                    'message' => 'Ligne ignorée.', 'ignore' => true, 'confirme' => false]);

                continue;
            }
            $etab = $valides->get((int) $id);
            if (! $etab) {
                $l = array_merge($l, ['etablissement_id' => null, 'etablissement_nom' => null, 'statut' => 'erreur',
                    'message' => 'Pharmacie introuvable dans cette ville.', 'ignore' => false, 'confirme' => false]);

                continue;
            }
            $l = array_merge($l, ['etablissement_id' => $etab->id, 'etablissement_nom' => $etab->nom.' ('.($etab->commune->nom ?? '').')',
                'statut' => 'ok', 'message' => null, 'ignore' => false, 'confirme' => true]);
        }
        unset($l);

        $lignes = $this->controlerDoublons($lignes);
        $nbOk = count(array_filter($lignes, fn ($r) => $r['statut'] === 'ok'));
        $lot->update(['lignes' => $lignes, 'nb_valides' => $nbOk, 'nb_erreurs' => count($lignes) - $nbOk]);

        return $lot;
    }

    /**
     * Parmi les lignes rattachées à une pharmacie : repère les doublons dans le lot et les gardes déjà existantes.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array<string, mixed>>
     */
    private function controlerDoublons(array $lignes): array
    {
        $vus = [];
        foreach ($lignes as &$r) {
            if ($r['etablissement_id'] === null || ! in_array($r['statut'], ['ok', 'doublon'], true)) {
                continue;
            }
            $r['statut'] = 'ok';
            $r['message'] = null;

            if (isset($vus[$r['etablissement_id']])) {
                $r['statut'] = 'doublon';
                $r['message'] = "En double avec la ligne {$vus[$r['etablissement_id']]}.";
            } elseif (Garde::where('etablissement_id', $r['etablissement_id'])->chevauche($r['date_debut'], $r['date_fin'])->exists()) {
                $r['statut'] = 'doublon';
                $r['message'] = 'Déjà de garde sur cette période : ligne ignorée.';
            } else {
                $vus[$r['etablissement_id']] = $r['ligne'];
            }
        }
        unset($r);

        return $lignes;
    }

    public function publier(ImportLot $lot): int
    {
        return DB::transaction(function () use ($lot) {
            $lot = ImportLot::lockForUpdate()->findOrFail($lot->id);
            if ($lot->statut !== 'apercu') {
                return 0;
            }

            $crees = 0;
            foreach ($lot->lignes as $l) {
                if ($l['statut'] !== 'ok') {
                    continue;
                }
                // Revérifie : un autre lot a pu être publié entre l'aperçu et la publication
                if (Garde::where('etablissement_id', $l['etablissement_id'])->chevauche($l['date_debut'], $l['date_fin'])->exists()) {
                    continue;
                }
                Garde::create([
                    'etablissement_id' => $l['etablissement_id'],
                    'date_debut' => $l['date_debut'],
                    'date_fin' => $l['date_fin'],
                    'source' => Str::limit('Import '.$lot->fichier_nom, 150, ''),
                    'lot_import_id' => $lot->id,
                ]);
                $crees++;
            }

            $lot->update(['statut' => 'publie', 'publie_at' => now()]);

            return $crees;
        });
    }

    /** @return array{0: ?Etablissement, 1: ?string} */
    private function trouver(string $id, string $nom, string $commune, Collection $parId, Collection $parNom): array
    {
        if ($id !== '') {
            $etab = ctype_digit($id) ? $parId->get((int) $id) : null;

            return $etab ? [$etab, null] : [null, "Id « {$id} » : aucune pharmacie de cette ville ne porte cet id."];
        }

        $candidats = $parNom->get($this->cle($nom), collect());
        if ($commune !== '' && $candidats->count() > 1) {
            $candidats = $candidats->filter(fn (Etablissement $e) => $this->cle($e->commune->nom) === $this->cle($commune));
        }

        return match (true) {
            $candidats->count() === 1 => [$candidats->first(), null],
            $candidats->isEmpty() => [null, 'Pharmacie introuvable : créez-la d\'abord dans Établissements ou indiquez son id.'],
            default => [null, 'Plusieurs pharmacies portent ce nom : précisez la commune ou l\'id.'],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function lire(UploadedFile $fichier): array
    {
        $type = match (strtolower($fichier->getClientOriginalExtension())) {
            'xlsx' => 'Xlsx',
            'xls' => 'Xls',
            default => 'Csv',
        };

        $reader = IOFactory::createReader($type);
        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::GUESS_ENCODING); // fichiers Excel FR souvent en Windows-1252
        }
        $reader->setReadDataOnly(true);

        $lignes = $reader->load($fichier->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $entetes = array_map(fn ($h) => $this->entete((string) $h), array_shift($lignes) ?? []);

        if (! in_array('nom', $entetes, true) && ! in_array('id', $entetes, true)) {
            throw ValidationException::withMessages([
                'fichier' => 'Colonnes attendues : nom (ou id), commune, date_debut, date_fin. Téléchargez le modèle.',
            ]);
        }

        return array_map(function (array $ligne) use ($entetes) {
            $assoc = [];
            foreach ($entetes as $i => $cle) {
                if ($cle !== '') {
                    $assoc[$cle] = $ligne[$i] ?? null;
                }
            }

            return $assoc;
        }, $lignes);
    }

    private function entete(string $brut): string
    {
        $cle = Str::of($brut)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();

        return self::ALIAS[$cle] ?? $cle;
    }

    /** Clé de rapprochement : sans accents, sans le mot « pharmacie », espaces normalisés. */
    private function cle(string $nom): string
    {
        return Str::of($nom)->ascii()->lower()
            ->replaceMatches('/\bpharmacie\b/', ' ')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    /** null = vide, false = illisible, sinon AAAA-MM-JJ. */
    private function date(mixed $valeur): string|false|null
    {
        if ($valeur === null || trim((string) $valeur) === '') {
            return null;
        }

        if (is_numeric($valeur)) {
            return ExcelDate::excelToDateTimeObject((float) $valeur)->format('Y-m-d');
        }

        $texte = trim((string) $valeur);
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y'] as $format) {
            $date = DateTime::createFromFormat('!'.$format, $texte);
            $erreurs = DateTime::getLastErrors();
            if ($date && (! $erreurs || ($erreurs['warning_count'] === 0 && $erreurs['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return false;
    }

    private function erreur(array $r, string $message): array
    {
        $r['statut'] = 'erreur';
        $r['message'] = $message;

        return $r;
    }
}
