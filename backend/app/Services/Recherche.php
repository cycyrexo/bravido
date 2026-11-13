<?php

namespace App\Services;

use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\EtablissementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * F10 : recherche globale (nom, services, catégories) avec synonymes français, insensible aux accents
 * et tolérante aux fautes de frappe légères. Le classement n'utilise JAMAIS la mise en avant « À la une ».
 */
class Recherche
{
    public const MAX_RESULTATS = 40;

    private const CANDIDATS_MAX = 400;

    /**
     * Synonymes (sans accents, minuscules) → intention : catégories et/ou mots-clés de services.
     * Les mots-clés de service sont des radicaux comparés en LIKE (la collation SQL ignore déjà les accents).
     *
     * @var array<string, array{cat?: list<string>, svc?: list<string>}>
     */
    public const SYNONYMES = [
        'pharmacie' => ['cat' => ['pharmacie']], 'pharmacies' => ['cat' => ['pharmacie']], 'phcie' => ['cat' => ['pharmacie']],
        'pharma' => ['cat' => ['pharmacie']], 'pharmacien' => ['cat' => ['pharmacie']], 'officine' => ['cat' => ['pharmacie']],
        'dentiste' => ['cat' => ['dentaire'], 'svc' => ['dentaire', 'dentisterie']], 'dentaire' => ['cat' => ['dentaire'], 'svc' => ['dentaire']],
        'dent' => ['cat' => ['dentaire'], 'svc' => ['dentaire']], 'dents' => ['cat' => ['dentaire'], 'svc' => ['dentaire']],
        'orthodontie' => ['cat' => ['dentaire'], 'svc' => ['orthodont']], 'stomatologie' => ['cat' => ['dentaire'], 'svc' => ['stomatolog']],
        'clinique' => ['cat' => ['hopital_prive']], 'cliniques' => ['cat' => ['hopital_prive']],
        'hopital' => ['cat' => ['hopital_public', 'hopital_prive']], 'hopitaux' => ['cat' => ['hopital_public', 'hopital_prive']],
        'chu' => ['cat' => ['hopital_public']], 'chr' => ['cat' => ['hopital_public']], 'hg' => ['cat' => ['hopital_public']],
        'dispensaire' => ['cat' => ['hopital_public']], 'csu' => ['cat' => ['hopital_public']],
        'optique' => ['cat' => ['optique']], 'optic' => ['cat' => ['optique']], 'lunettes' => ['cat' => ['optique']], 'lunette' => ['cat' => ['optique']],
        'opticien' => ['cat' => ['optique']], 'optician' => ['cat' => ['optique']], 'lentilles' => ['cat' => ['optique']],
        'ophtalmo' => ['cat' => ['optique'], 'svc' => ['ophtalmolog']], 'ophtalmologie' => ['cat' => ['optique'], 'svc' => ['ophtalmolog']],
        'veterinaire' => ['cat' => ['veterinaire']], 'veto' => ['cat' => ['veterinaire']], 'vet' => ['cat' => ['veterinaire']],
        'animaux' => ['cat' => ['veterinaire']], 'animal' => ['cat' => ['veterinaire']],
        'labo' => ['cat' => ['laboratoire'], 'svc' => ['laboratoire']], 'laboratoire' => ['cat' => ['laboratoire'], 'svc' => ['laboratoire']],
        'analyses' => ['cat' => ['laboratoire'], 'svc' => ['laboratoire', 'analyse']], 'analyse' => ['cat' => ['laboratoire'], 'svc' => ['laboratoire', 'analyse']],
        'prelevement' => ['cat' => ['laboratoire']],
        'radio' => ['cat' => ['imagerie'], 'svc' => ['radiolog']], 'radiologie' => ['cat' => ['imagerie'], 'svc' => ['radiolog']],
        'echographie' => ['cat' => ['imagerie'], 'svc' => ['echograph']], 'echo' => ['cat' => ['imagerie'], 'svc' => ['echograph']],
        'imagerie' => ['cat' => ['imagerie'], 'svc' => ['imagerie']], 'scanner' => ['cat' => ['imagerie'], 'svc' => ['scanner']],
        'irm' => ['cat' => ['imagerie'], 'svc' => ['irm']],
        'maternite' => ['svc' => ['maternit', 'accouchement']], 'accouchement' => ['svc' => ['accouchement', 'maternit']],
        'urgences' => ['cat' => ['centre_secours'], 'svc' => ['urgence']], 'urgence' => ['cat' => ['centre_secours'], 'svc' => ['urgence']],
        'pediatre' => ['svc' => ['pediatr']], 'pediatrie' => ['svc' => ['pediatr']], 'enfant' => ['svc' => ['pediatr']], 'enfants' => ['svc' => ['pediatr']],
        'gyneco' => ['svc' => ['gynecolog']], 'gynecologie' => ['svc' => ['gynecolog']], 'gynecologue' => ['svc' => ['gynecolog']],
        'cardio' => ['svc' => ['cardiolog']], 'cardiologie' => ['svc' => ['cardiolog']], 'cardiologue' => ['svc' => ['cardiolog']],
        'dermato' => ['svc' => ['dermatolog']], 'dermatologie' => ['svc' => ['dermatolog']],
        'orl' => ['svc' => ['orl', 'o.r.l']], 'urologie' => ['svc' => ['urolog']], 'neurologie' => ['svc' => ['neurolog']],
        'chirurgie' => ['svc' => ['chirurgie']], 'vaccin' => ['svc' => ['vaccin']], 'vaccination' => ['svc' => ['vaccin']],
        'kine' => ['svc' => ['kinesith']], 'kinesitherapie' => ['svc' => ['kinesith']],
        'pompiers' => ['cat' => ['centre_secours']], 'pompier' => ['cat' => ['centre_secours']], 'sapeurs' => ['cat' => ['centre_secours']],
        'gspm' => ['cat' => ['centre_secours']], 'onpc' => ['cat' => ['centre_secours']], 'secours' => ['cat' => ['centre_secours']],
        'fga' => ['cat' => ['bureau_fga']], 'accident' => ['cat' => ['bureau_fga']],
        'militaire' => ['cat' => ['militaire']], 'armee' => ['cat' => ['militaire']],
    ];

    /** Mots vides ignorés. */
    private const VIDES = ['de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'a', 'au', 'aux', 'pres', 'en', 'd', 'l'];

    /**
     * @param  array{ville_id?: int|null, lat?: float|null, lng?: float|null, categorie?: string|null}  $f
     * @return array{items: Collection<int, Etablissement>, total: int, groupes: list<array{code: string, libelle: string, total: int}>, interprete: array}
     */
    public function chercher(string $q, array $f = []): array
    {
        $analyse = $this->analyser($q);
        if ($analyse['jetons'] === []) {
            return ['items' => collect(), 'total' => 0, 'groupes' => [], 'interprete' => $analyse['interprete']];
        }

        $ids = $this->trouverIds($analyse, $f);
        if ($ids->isEmpty()) {
            // Dernier recours : tolérance aux fautes de frappe sur le vocabulaire des noms
            $analyse = $this->corrigerParVocabulaire($analyse);
            $ids = $this->trouverIds($analyse, $f);
        }

        $base = $this->requeteBase($f)->whereIn('etablissements.id', $ids->take(self::CANDIDATS_MAX)->all());
        $tous = $base->get();

        // Comptes par catégorie sur l'ensemble des correspondances (avant filtre de catégorie)
        $groupes = $tous->groupBy(fn ($e) => $e->categorie?->code)->map(fn ($g, $code) => [
            'code' => (string) $code,
            'libelle' => (string) $g->first()->categorie?->libelle,
            'total' => $g->count(),
        ])->sortByDesc('total')->values()->all();

        if (! empty($f['categorie'])) {
            $codes = array_filter(array_map('trim', explode(',', $f['categorie'])));
            $tous = $tous->filter(fn ($e) => in_array($e->categorie?->code, $codes, true));
        }

        $classes = $tous->map(function ($e) use ($analyse, $ids) {
            $e->setAttribute('score_recherche', $this->score($e, $analyse) - ($ids->search($e->id) / 1000));

            return $e;
        })->sortByDesc(fn ($e) => $e->score_recherche)->values();

        return [
            'items' => $classes->take(self::MAX_RESULTATS)->values(),
            'total' => $classes->count(),
            'groupes' => $groupes,
            'interprete' => $analyse['interprete'],
        ];
    }

    /** Suggestions rapides : catégories, services et noms d'établissements (au plus 8). */
    public function suggestions(string $q): array
    {
        $analyse = $this->analyser($q);
        $out = [];
        $norm = $analyse['norm'];
        if (mb_strlen($norm) < 2) {
            return [];
        }

        foreach (array_slice($analyse['interprete']['categories'], 0, 2) as $code) {
            $cat = Categorie::where('code', $code)->first();
            if ($cat) {
                $out[] = ['type' => 'categorie', 'libelle' => $cat->libelle, 'valeur' => $cat->code];
            }
        }

        $services = EtablissementService::query()->where('service', 'like', '%'.$this->like($norm).'%')
            ->whereRaw('CHAR_LENGTH(service) <= 40')->select('service')->distinct()->orderBy('service')->limit(3)->pluck('service');
        foreach ($services as $s) {
            $out[] = ['type' => 'service', 'libelle' => $s, 'valeur' => $s];
        }

        $reste = 8 - count($out);
        $etabs = Etablissement::query()->publie()->with(['categorie:id,code', 'commune:id,nom'])
            ->where('etablissements.nom', 'like', '%'.$this->like($norm).'%')
            ->orderByRaw('etablissements.nom LIKE ? DESC', [$this->like($norm).'%'])->orderBy('etablissements.nom')
            ->limit(max(2, $reste))->get();
        foreach ($etabs as $e) {
            $out[] = [
                'type' => 'etablissement', 'libelle' => $e->nom, 'valeur' => $e->nom, 'id' => $e->id,
                'categorie' => $e->categorie?->code, 'commune' => $e->commune?->nom,
            ];
        }

        return array_slice($out, 0, 8);
    }

    /** Normalise (minuscules, sans accents), découpe, corrige les mots proches d'un synonyme, déduit l'intention. */
    public function analyser(string $q): array
    {
        $norm = self::normaliser($q);
        $jetons = array_values(array_filter(preg_split('/\s+/', $norm) ?: [], fn ($t) => $t !== '' && ! in_array($t, self::VIDES, true)));

        $cats = [];
        $svcs = [];
        $restants = [];
        $corriges = [];
        foreach ($jetons as $t) {
            $cle = $t;
            if (! isset(self::SYNONYMES[$cle])) {
                $cle = $this->synonymeProche($t);
                if ($cle) {
                    $corriges[$t] = $cle;
                }
            }
            if ($cle && isset(self::SYNONYMES[$cle])) {
                $cats = array_merge($cats, self::SYNONYMES[$cle]['cat'] ?? []);
                $svcs = array_merge($svcs, self::SYNONYMES[$cle]['svc'] ?? []);
            } else {
                $restants[] = $t;
            }
        }

        return [
            'norm' => $norm,
            'jetons' => $jetons,
            // chaque jeton : alternatives acceptées dans le nom
            'alternatives' => array_map(fn ($t) => [$t], $jetons),
            'restants' => $restants,
            'categories' => array_values(array_unique($cats)),
            'services' => array_values(array_unique($svcs)),
            'interprete' => ['categories' => array_values(array_unique($cats)), 'services' => array_values(array_unique($svcs)), 'corriges' => $corriges],
        ];
    }

    public static function normaliser(string $s): string
    {
        $s = Str::lower(Str::ascii($s));
        $s = preg_replace('/[^a-z0-9.\s]+/', ' ', $s) ?? '';

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    private function synonymeProche(string $t): ?string
    {
        $len = strlen($t);
        if ($len < 5) {
            return null;
        }
        $tolerance = $len >= 8 ? 2 : 1;
        $meilleur = null;
        $d0 = $tolerance + 1;
        foreach (array_keys(self::SYNONYMES) as $cle) {
            if (abs(strlen($cle) - $len) > $tolerance) {
                continue;
            }
            $d = levenshtein($t, $cle);
            if ($d < $d0) {
                $d0 = $d;
                $meilleur = $cle;
            }
        }

        return $meilleur;
    }

    private function corrigerParVocabulaire(array $analyse): array
    {
        $vocab = Cache::remember('recherche_vocabulaire', 600, function () {
            $mots = [];
            Etablissement::query()->publie()->pluck('nom')->each(function ($nom) use (&$mots) {
                foreach (explode(' ', self::normaliser($nom)) as $m) {
                    if (strlen($m) >= 4) {
                        $mots[$m] = true;
                    }
                }
            });

            return array_keys($mots);
        });

        $alts = [];
        foreach ($analyse['jetons'] as $t) {
            $a = [$t];
            $len = strlen($t);
            if ($len >= 4) {
                $tol = $len >= 8 ? 2 : 1;
                foreach ($vocab as $m) {
                    if (abs(strlen($m) - $len) <= $tol && levenshtein($t, $m) <= $tol) {
                        $a[] = $m;
                    }
                }
            }
            $alts[] = array_slice($a, 0, 8);
        }
        $analyse['alternatives'] = $alts;
        $analyse['corrige'] = true;

        return $analyse;
    }

    private function requeteBase(array $f): Builder
    {
        $q = Etablissement::query()->publie()
            ->with(['categorie:id,code,libelle', 'commune:id,ville_id,nom', 'commune.ville:id,nom', 'services:id,etablissement_id,service'])
            ->withCount('assurances')
            ->withExists(['gardes as de_garde' => fn (Builder $g) => $g->enCours()]);

        if (! empty($f['ville_id'])) {
            $q->dansVille((int) $f['ville_id']);
        }
        if (isset($f['lat'], $f['lng'])) {
            $q->avecDistance((float) $f['lat'], (float) $f['lng']);
        }

        return $q;
    }

    /** Identifiants des établissements correspondants (ordre grossier). */
    private function trouverIds(array $a, array $f): Collection
    {
        $q = Etablissement::query()->publie()->select('etablissements.id');
        if (! empty($f['ville_id'])) {
            $q->dansVille((int) $f['ville_id']);
        }

        $nomContient = function (Builder $b, array $alternatives) {
            foreach ($alternatives as $alts) {
                $b->where(function (Builder $w) use ($alts) {
                    foreach ($alts as $mot) {
                        $w->orWhere('etablissements.nom', 'like', '%'.$this->like($mot).'%');
                    }
                });
            }
        };
        $restantsAlt = array_values(array_filter($a['alternatives'], fn ($alts) => in_array($alts[0], $a['restants'], true)));
        $aIntention = $a['categories'] !== [] || $a['services'] !== [];

        $q->where(function (Builder $w) use ($a, $nomContient, $restantsAlt, $aIntention) {
            // A. tous les mots figurent dans le nom
            $w->where(fn (Builder $b) => $nomContient($b, $a['alternatives']));

            if ($aIntention) {
                // B. intention (catégorie ou service) + éventuels mots restants dans le nom
                $w->orWhere(function (Builder $b) use ($a, $nomContient, $restantsAlt) {
                    $b->where(function (Builder $i) use ($a) {
                        if ($a['categories'] !== []) {
                            $i->orWhereIn('etablissements.categorie_id', Categorie::select('id')->whereIn('code', $a['categories']));
                        }
                        foreach ($a['services'] as $s) {
                            $i->orWhereHas('services', fn (Builder $sv) => $sv->where('service', 'like', '%'.$this->like($s).'%'));
                        }
                    });
                    $nomContient($b, $restantsAlt);
                });
            } else {
                // C. un service dont le libellé contient la requête
                $w->orWhereHas('services', fn (Builder $sv) => $sv->where('service', 'like', '%'.$this->like($a['norm']).'%'));
            }
        });

        return $q->orderBy('etablissements.nom')->limit(1500)->pluck('etablissements.id');
    }

    /**
     * Classement : nom exact > nom commençant par > mot du nom commençant par > nom contenant > catégorie > service.
     * Bonus de proximité (positions précises uniquement) : 0 à 60 points, sans jamais dépasser le palier suivant.
     */
    private function score(Etablissement $e, array $a): float
    {
        $nom = self::normaliser($e->nom);
        $q = $a['norm'];
        $score = 0.0;

        if ($nom === $q) {
            $score = 1000;
        } elseif (str_starts_with($nom, $q)) {
            $score = 800;
        } elseif (preg_match('/(^|\s)'.preg_quote($q, '/').'/', $nom)) {
            $score = 650;
        } elseif (str_contains($nom, $q)) {
            $score = 500;
        } else {
            $tousDansNom = $a['jetons'] !== [] && collect($a['alternatives'])->every(
                fn ($alts) => collect($alts)->contains(fn ($m) => str_contains($nom, $m))
            );
            if ($tousDansNom) {
                $score = ! empty($a['corrige']) ? 250 : 450;
            }
        }

        $catOk = $a['categories'] !== [] && in_array($e->categorie?->code, $a['categories'], true);
        if ($score < 300 && $catOk) {
            $score = 300;
        }
        if ($catOk && $score >= 300) {
            $score += 20; // catégorie correspondante : léger plus
        }
        if ($score === 0.0) {
            $score = 200; // correspondance par service
        }

        // Proximité : seulement si la position est précise (distance_m non NULL)
        $d = $e->getAttributes()['distance_m'] ?? null;
        if ($e->position_precise) {
            $score += 10;
            if ($d !== null) {
                $score += 50 * exp(-((float) $d) / 5000);
            }
        }

        return $score;
    }

    private function like(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
