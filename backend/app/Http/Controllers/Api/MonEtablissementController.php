<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\EtabPhoto;
use App\Models\Fiche;
use App\Models\GardeDeclaration;
use App\Services\DeclarationsGarde;
use App\Services\EtabStats;
use App\Services\PhotosEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * F18 : espace du responsable d'établissement. Toutes les routes exigent que l'utilisateur
 * soit le responsable validé (etablissements.responsable_id) de l'établissement visé.
 */
class MonEtablissementController extends Controller
{
    public const JOURS = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];

    public function index(Request $request, EtabStats $stats): JsonResponse
    {
        $liste = Etablissement::where('responsable_id', $request->user()->id)
            ->with(['categorie:id,code,libelle', 'commune:id,ville_id,nom', 'commune.ville:id,nom'])
            ->orderBy('nom')->get();
        $resumes = $stats->resumes($liste->pluck('id')->all());

        return response()->json(['data' => $liste->map(fn (Etablissement $e) => [
            'id' => $e->id,
            'nom' => $e->nom,
            'categorie' => ['code' => $e->categorie->code, 'libelle' => $e->categorie->libelle],
            'commune' => $e->commune->nom,
            'ville' => $e->commune->ville->nom,
            'statut' => $e->statut,
            'premium' => $e->estPremium(),
            'stats' => $resumes[$e->id] ?? ['vues' => 0, 'appels' => 0, 'itineraires' => 0],
        ])]);
    }

    public function show(Request $request, Etablissement $etablissement, EtabStats $stats): JsonResponse
    {
        $this->autoriser($request, $etablissement);
        $etablissement->load(['categorie', 'commune.ville', 'assurances', 'services', 'photos']);

        $fiche = (new EtablissementResource($etablissement))->resolve($request);

        return response()->json(['data' => $fiche + [
            'statut' => $etablissement->statut,
            'premium_jusqu_au' => $etablissement->premium_jusqu_au?->toDateString(),
            'peut_declarer_garde' => $etablissement->categorie->code === Categorie::PHARMACIE,
            'declarations_garde' => GardeDeclaration::where('etablissement_id', $etablissement->id)
                ->latest('id')->limit(6)->get()->map(fn (GardeDeclaration $d) => [
                    'id' => $d->id,
                    'du' => $d->date_debut->toDateString(),
                    'au' => $d->date_fin->toDateString(),
                    'statut' => $d->statut,
                    'motif_rejet' => $d->motif_rejet,
                ]),
            'correction_en_attente' => Fiche::where('etablissement_id', $etablissement->id)
                ->where('type', 'correction')->where('statut', 'en_attente')->where('soumis_par', $request->user()->id)->exists(),
            'stats' => $stats->resume($etablissement->id),
            'photos_max' => PhotosEtablissement::MAX,
            'horaires_detail_edit' => $etablissement->estPremium() ? ($etablissement->horaires_detail ?? (object) []) : null,
        ]]);
    }

    /** Modification des coordonnées, horaires, services, assurances : fiche de correction modérée (mécanisme existant). */
    public function correction(Request $request, Etablissement $etablissement): JsonResponse
    {
        $this->autoriser($request, $etablissement);
        $data = $request->validate([
            'tel1' => ['nullable', 'string', 'max:20'],
            'tel2' => ['nullable', 'string', 'max:20'],
            'horaires' => ['nullable', 'string', 'max:255'],
            'h24' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_longue' => ['nullable', 'string', 'max:4000'],
            'services' => ['nullable', 'array', 'max:30'],
            'services.*' => ['string', 'max:100'],
            'assurances' => ['nullable', 'array', 'max:50'],
            'assurances.*' => ['integer', 'exists:assurances,id'],
        ]);
        if (! $etablissement->estPremium()) {
            unset($data['description_longue']); // réservé Premium
        }
        $donnees = Arr::only($data, ['tel1', 'tel2', 'horaires', 'h24', 'description', 'description_longue', 'services', 'assurances']);
        $donnees = array_filter($donnees, fn ($v) => $v !== null);
        if (isset($donnees['h24'])) {
            $donnees['h24'] = filter_var($donnees['h24'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($donnees === []) {
            return response()->json(['message' => 'Aucune modification à envoyer.'], 422);
        }
        if (Fiche::where('etablissement_id', $etablissement->id)->where('type', 'correction')
            ->where('soumis_par', $request->user()->id)->where('statut', 'en_attente')->exists()) {
            return response()->json(['message' => 'Une modification est déjà en attente de validation.'], 409);
        }

        $fiche = new Fiche(['type' => 'correction', 'etablissement_id' => $etablissement->id, 'donnees' => $donnees]);
        $fiche->soumis_par = $request->user()->id;
        $fiche->statut = 'en_attente';
        $fiche->save();

        return response()->json(['message' => 'Modification envoyée : elle sera visible après validation par notre équipe.'], 201);
    }

    /** « Déclarer ma garde » : déclaration toujours modérée avant publication. */
    public function garde(Request $request, Etablissement $etablissement, DeclarationsGarde $service): JsonResponse
    {
        $this->autoriser($request, $etablissement);
        $etablissement->loadMissing('categorie');
        abort_unless($etablissement->categorie->code === Categorie::PHARMACIE, 403, 'Seules les pharmacies déclarent une garde.');

        $data = $request->validate([
            'date_debut' => ['required', 'date_format:Y-m-d'],
            'date_fin' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $d = $service->declarer($etablissement, $request->user(), $data['date_debut'], $data['date_fin']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Déclaration envoyée. La garde sera publiée après validation par notre équipe.',
            'data' => ['id' => $d->id, 'statut' => $d->statut],
        ], 201);
    }

    // ---- Premium ----

    public function ajouterPhoto(Request $request, Etablissement $etablissement, PhotosEtablissement $photos): JsonResponse
    {
        $this->autoriser($request, $etablissement);
        $this->exigerPremium($etablissement);
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);

        try {
            $p = $photos->ajouter($etablissement, $request->file('photo'));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $p->id, 'url' => $p->url(), 'miniature' => $p->urlMiniature()]], 201);
    }

    public function supprimerPhoto(Request $request, Etablissement $etablissement, EtabPhoto $photo, PhotosEtablissement $photos): JsonResponse
    {
        $this->autoriser($request, $etablissement);
        abort_unless($photo->etablissement_id === $etablissement->id, 404);
        $photos->supprimer($photo);

        return response()->json(['message' => 'Photo supprimée.']);
    }

    /** Horaires détaillés par jour (Premium) : stockés tels quels, affichés dans la fiche. */
    public function horairesDetail(Request $request, Etablissement $etablissement): JsonResponse
    {
        $this->autoriser($request, $etablissement);
        $this->exigerPremium($etablissement);
        $data = $request->validate([
            'jours' => ['required', 'array'],
            'jours.*' => ['nullable', 'string', 'max:60'],
        ]);
        $jours = [];
        foreach (self::JOURS as $j) {
            $v = trim((string) ($data['jours'][$j] ?? ''));
            if ($v !== '') {
                $jours[$j] = $v;
            }
        }
        $etablissement->forceFill(['horaires_detail' => $jours ?: null])->save();

        return response()->json(['data' => $jours ?: (object) []]);
    }

    private function autoriser(Request $request, Etablissement $etablissement): void
    {
        abort_unless($etablissement->responsable_id !== null && $etablissement->responsable_id === $request->user()->id, 403,
            'Vous n\'êtes pas le responsable de cet établissement.');
    }

    private function exigerPremium(Etablissement $etablissement): void
    {
        abort_unless($etablissement->estPremium(), 403, 'Fonction réservée aux fiches Premium.');
    }
}
