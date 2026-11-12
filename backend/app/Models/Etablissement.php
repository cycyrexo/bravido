<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Etablissement extends Model
{
    // Champs qu'une fiche de souscription / correction peut modifier
    public const CHAMPS_EDITABLES = [
        'nom', 'commune_id', 'adresse', 'repere', 'description',
        'lat', 'lng', 'tel1', 'tel2', 'horaires', 'h24',
    ];

    protected $fillable = [
        'categorie_id', 'nom', 'commune_id', 'adresse', 'repere', 'description',
        'lat', 'lng', 'tel1', 'tel2', 'horaires', 'h24', 'statut', 'premium', 'verifie_at',
        'position_precise', 'tel_autres', 'email', 'site_web', 'responsable_nom',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'position_precise' => 'boolean',
            'h24' => 'boolean',
            'premium' => 'boolean',
            'verifie_at' => 'datetime',
        ];
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(EtablissementService::class)->orderBy('service');
    }

    public function assurances(): BelongsToMany
    {
        return $this->belongsToMany(Assurance::class, 'etab_assurances')->orderBy('nom');
    }

    public function gardes(): HasMany
    {
        return $this->hasMany(Garde::class);
    }

    public function scopePublie(Builder $query): Builder
    {
        return $query->where('etablissements.statut', 'publie');
    }

    public function scopeDeCategorie(Builder $query, string|array $codes): Builder
    {
        return $query->whereIn(
            'etablissements.categorie_id',
            Categorie::select('id')->whereIn('code', (array) $codes)
        );
    }

    public function scopeDansVille(Builder $query, int $villeId): Builder
    {
        return $query->whereIn(
            'etablissements.commune_id',
            Commune::select('id')->where('ville_id', $villeId)
        );
    }

    /**
     * Ajoute la colonne distance_m (Haversine, en mètres).
     */
    public function scopeAvecDistance(Builder $query, float $lat, float $lng): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select('etablissements.*');
        }

        // Position approximative (centre de commune) : pas de distance, pour ne jamais afficher un chiffre faux
        return $query->selectRaw(
            'CASE WHEN etablissements.position_precise = 1 THEN '
            .'(6371000 * 2 * ASIN(LEAST(1, SQRT('
            .'POWER(SIN(RADIANS(etablissements.lat - ?) / 2), 2) + '
            .'COS(RADIANS(?)) * COS(RADIANS(etablissements.lat)) * '
            .'POWER(SIN(RADIANS(etablissements.lng - ?) / 2), 2)'
            .')))) ELSE NULL END AS distance_m',
            [$lat, $lat, $lng]
        );
    }

    /** Du plus proche au plus loin ; les positions approximatives ensuite, par nom. */
    public function scopeTriParDistance(Builder $query): Builder
    {
        return $query->orderByRaw('distance_m IS NULL')->orderBy('distance_m')->orderBy('etablissements.nom');
    }

    /**
     * Pré-filtre par boîte englobante : exploite l'index (lat, lng)
     * avant le calcul de distance.
     */
    public function scopeDansRayon(Builder $query, float $lat, float $lng, float $km): Builder
    {
        $dLat = $km / 111.32;
        $dLng = $km / (111.32 * max(cos(deg2rad($lat)), 0.01));

        return $query
            ->whereBetween('etablissements.lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('etablissements.lng', [$lng - $dLng, $lng + $dLng]);
    }

    /**
     * @param  string[]  $services
     */
    public function syncServices(array $services): void
    {
        $services = array_values(array_unique(array_filter(array_map('trim', $services))));
        $this->services()->whereNotIn('service', $services)->delete();
        foreach ($services as $service) {
            $this->services()->firstOrCreate(['service' => $service]);
        }
    }
}
