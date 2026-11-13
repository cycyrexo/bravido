<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Assurance;
use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\Fiche;
use App\Models\Ville;
use App\Services\Notifier;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** BO-04 : validation des fiches de souscription et de correction. */
class FicheController extends Controller
{
    public const LIBELLES = [
        'nom' => 'Dénomination', 'categorie_code' => 'Catégorie', 'commune_id' => 'Commune',
        'adresse' => 'Adresse', 'repere' => 'Repère', 'description' => 'Description',
        'lat' => 'Latitude', 'lng' => 'Longitude', 'tel1' => 'Téléphone 1', 'tel2' => 'Téléphone 2',
        'horaires' => 'Horaires', 'h24' => 'Ouvert 24 h/24', 'services' => 'Services', 'assurances' => 'Assurances',
        'description_longue' => 'Description détaillée (Premium)',
        'role' => 'Fonction du demandeur', 'telephone' => 'Téléphone du demandeur',
    ];

    public function __construct(private readonly Notifier $notifier) {}

    public function index(Request $request): View
    {
        $statut = $request->validate(['statut' => ['nullable', 'in:en_attente,validee,rejetee']])['statut'] ?? 'en_attente';

        return view('admin.fiches.index', [
            'fiches' => Fiche::with(['auteur', 'etablissement'])->where('statut', $statut)->latest()->paginate(25)->withQueryString(),
            'statut' => $statut,
        ]);
    }

    public function show(Fiche $fiche): View
    {
        $fiche->load(['auteur', 'traitePar', 'etablissement.categorie', 'etablissement.commune', 'etablissement.services', 'etablissement.assurances']);

        return view('admin.fiches.show', [
            'fiche' => $fiche,
            'lignes' => $this->comparaison($fiche),
            'categories' => Categorie::orderBy('ordre')->get(),
            'villes' => Ville::with('communes')->orderBy('nom')->get(),
        ]);
    }

    public function valider(Request $request, Fiche $fiche): RedirectResponse
    {
        abort_unless($fiche->statut === 'en_attente', 409, 'Cette fiche a déjà été traitée.');
        $donnees = $fiche->donnees;

        if ($fiche->type === 'souscription') {
            // Le modérateur confirme les champs indispensables (notamment la position GPS)
            $confirmes = $request->validate([
                'nom' => ['required', 'string', 'max:200'],
                'categorie_id' => ['required', 'integer', 'exists:categories,id'],
                'commune_id' => ['required', 'integer', 'exists:communes,id'],
                'lat' => ['required', 'numeric', 'between:-90,90'],
                'lng' => ['required', 'numeric', 'between:-180,180'],
            ]);

            $etab = DB::transaction(function () use ($fiche, $donnees, $confirmes, $request) {
                $etab = new Etablissement(array_merge(Arr::only($donnees, Etablissement::CHAMPS_EDITABLES), $confirmes));
                $etab->statut = 'publie';
                $etab->verifie_at = now();
                $etab->responsable_id = $fiche->soumis_par;
                $etab->save();
                $etab->syncServices($donnees['services'] ?? []);
                $etab->assurances()->sync($donnees['assurances'] ?? []);

                if ($fiche->auteur->role === 'user') {
                    $fiche->auteur->forceFill(['role' => 'resp_etab'])->save();
                }

                $this->marquer($fiche, 'validee', $request, ['etablissement_id' => $etab->id]);

                return $etab;
            });

            Journal::log('fiche.validation_souscription', $fiche, null, $etab->toArray());
        } elseif ($fiche->type === 'revendication') {
            $etab = $fiche->etablissement;
            abort_unless($etab, 422, "L'établissement concerné n'existe plus.");
            $avant = ['responsable_id' => $etab->responsable_id];

            DB::transaction(function () use ($fiche, $etab, $request) {
                $etab->forceFill(['responsable_id' => $fiche->soumis_par])->save();
                // L'utilisateur garde son accès normal ; seuls les comptes « user » passent resp_etab
                if ($fiche->auteur->role === 'user') {
                    $fiche->auteur->forceFill(['role' => 'resp_etab'])->save();
                }
                $this->marquer($fiche, 'validee', $request);
            });

            Journal::log('fiche.validation_revendication', $etab, $avant, ['responsable_id' => $fiche->soumis_par]);
        } else {
            $etab = $fiche->etablissement;
            abort_unless($etab, 422, 'L\'établissement concerné n\'existe plus.');

            $champs = Arr::only($donnees, Etablissement::CHAMPS_EDITABLES);
            $avant = $etab->only(array_keys($champs));

            DB::transaction(function () use ($fiche, $etab, $champs, $donnees, $request) {
                $etab->fill($champs);
                if (! empty($donnees['categorie_code'])) {
                    $etab->categorie_id = Categorie::idPour($donnees['categorie_code']) ?? $etab->categorie_id;
                }
                $etab->verifie_at = now();
                $etab->save();
                if (array_key_exists('services', $donnees)) {
                    $etab->syncServices($donnees['services']);
                }
                if (array_key_exists('assurances', $donnees)) {
                    $etab->assurances()->sync($donnees['assurances']);
                }
                $this->marquer($fiche, 'validee', $request);
            });

            Journal::log('fiche.validation_correction', $etab, $avant, $etab->only(array_keys($champs)));
        }

        $this->notifier->push($fiche->auteur, 'BRAVIDO', $fiche->type === 'revendication' ? 'Vous êtes maintenant responsable de « '.$etab->nom.' » : retrouvez-le dans Espace utilisateur › Mon établissement.' : 'Votre fiche a été validée. Merci pour votre contribution.', [
            'type' => 'fiche', 'fiche_id' => $fiche->id,
        ]);

        return redirect()->route('admin.fiches.index')->with('succes', 'Fiche validée.');
    }

    public function rejeter(Request $request, Fiche $fiche): RedirectResponse
    {
        abort_unless($fiche->statut === 'en_attente', 409, 'Cette fiche a déjà été traitée.');
        $data = $request->validate(['motif_rejet' => ['required', 'string', 'max:1000']]);

        $this->marquer($fiche, 'rejetee', $request, $data);
        Journal::log('fiche.rejet', $fiche, null, $data);

        $this->notifier->push($fiche->auteur, 'BRAVIDO', 'Votre '.($fiche->type === 'revendication' ? 'demande' : 'fiche')." n'a pas pu être validée : ".$data['motif_rejet'], [
            'type' => 'fiche', 'fiche_id' => $fiche->id,
        ]);

        return redirect()->route('admin.fiches.index')->with('succes', 'Fiche rejetée.');
    }

    public function piece(Fiche $fiche, int $index): StreamedResponse
    {
        $piece = $fiche->pieces[$index] ?? abort(404);
        abort_unless(Storage::disk('local')->exists($piece['chemin']), 404);

        return Storage::disk('local')->download($piece['chemin'], $piece['nom']);
    }

    private function marquer(Fiche $fiche, string $statut, Request $request, array $extra = []): void
    {
        $fiche->forceFill(array_merge([
            'statut' => $statut,
            'traite_par' => $request->user()->id,
            'traite_at' => now(),
        ], $extra))->save();
    }

    /** Lignes « avant / après » pour l'écran de comparaison. */
    private function comparaison(Fiche $fiche): array
    {
        $etab = $fiche->etablissement;
        $donnees = $fiche->donnees;
        $assurances = Assurance::pluck('nom', 'id');
        $communes = \App\Models\Commune::pluck('nom', 'id');

        $actuel = $etab ? [
            'nom' => $etab->nom,
            'categorie_code' => $etab->categorie?->code,
            'commune_id' => $etab->commune_id,
            'adresse' => $etab->adresse, 'repere' => $etab->repere, 'description' => $etab->description,
            'lat' => $etab->lat, 'lng' => $etab->lng, 'tel1' => $etab->tel1, 'tel2' => $etab->tel2,
            'horaires' => $etab->horaires, 'h24' => $etab->h24,
            'services' => $etab->services->pluck('service')->all(),
            'assurances' => $etab->assurances->pluck('id')->all(),
        ] : [];

        $afficher = function (string $champ, mixed $valeur) use ($assurances, $communes): string {
            return match (true) {
                $valeur === null || $valeur === [] => '—',
                $champ === 'h24' => $valeur ? 'Oui' : 'Non',
                $champ === 'commune_id' => $communes[$valeur] ?? (string) $valeur,
                $champ === 'assurances' => collect($valeur)->map(fn ($id) => $assurances[$id] ?? "#{$id}")->implode(', '),
                is_array($valeur) => implode(', ', $valeur),
                default => (string) $valeur,
            };
        };

        $lignes = [];
        foreach (self::LIBELLES as $champ => $libelle) {
            if (! array_key_exists($champ, $donnees) && ! array_key_exists($champ, $actuel)) {
                continue;
            }
            $avant = $afficher($champ, $actuel[$champ] ?? null);
            $apres = array_key_exists($champ, $donnees) ? $afficher($champ, $donnees[$champ]) : $avant;
            $lignes[] = ['libelle' => $libelle, 'avant' => $avant, 'apres' => $apres, 'modifie' => $avant !== $apres];
        }

        return $lignes;
    }
}
