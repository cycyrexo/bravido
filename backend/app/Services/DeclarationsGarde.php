<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\Garde;
use App\Models\GardeDeclaration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** F18 : déclarations de garde des responsables, modérées avant publication (une fausse info de garde est dangereuse). */
class DeclarationsGarde
{
    public const DUREE_MAX_JOURS = 14;

    public const HORIZON_JOURS = 60;

    /** Vérifie les dates et les chevauchements ; lève une \DomainException avec un message lisible. */
    public function verifier(Etablissement $etab, string $debut, string $fin, ?int $ignorerDeclaration = null): void
    {
        if ($fin < $debut) {
            throw new \DomainException('La date de fin doit être postérieure à la date de début.');
        }
        if ($debut < today()->toDateString()) {
            throw new \DomainException('La période de garde ne peut pas commencer dans le passé.');
        }
        if ($debut > today()->addDays(self::HORIZON_JOURS)->toDateString()) {
            throw new \DomainException('Déclaration possible '.self::HORIZON_JOURS.' jours à l\'avance au maximum.');
        }
        if (\Illuminate\Support\Carbon::parse($debut)->diffInDays($fin) + 1 > self::DUREE_MAX_JOURS) {
            throw new \DomainException('Une période de garde ne peut pas dépasser '.self::DUREE_MAX_JOURS.' jours.');
        }

        if (Garde::where('etablissement_id', $etab->id)->chevauche($debut, $fin)->exists()) {
            throw new \DomainException('Une garde est déjà enregistrée sur tout ou partie de cette période.');
        }
        $attente = GardeDeclaration::where('etablissement_id', $etab->id)->where('statut', 'en_attente')
            ->where('date_debut', '<=', $fin)->where('date_fin', '>=', $debut)
            ->when($ignorerDeclaration, fn ($q) => $q->where('id', '!=', $ignorerDeclaration))->exists();
        if ($attente) {
            throw new \DomainException('Une déclaration est déjà en attente sur tout ou partie de cette période.');
        }
    }

    public function declarer(Etablissement $etab, User $user, string $debut, string $fin): GardeDeclaration
    {
        $this->verifier($etab, $debut, $fin);
        $d = new GardeDeclaration(['date_debut' => $debut, 'date_fin' => $fin]);
        $d->etablissement_id = $etab->id;
        $d->user_id = $user->id;
        $d->statut = 'en_attente';
        $d->save();

        return $d;
    }

    /** Publie la déclaration : crée la garde (jamais de chevauchement) et clôt la déclaration. */
    public function valider(GardeDeclaration $d, User $moderateur): Garde
    {
        if ($d->statut !== 'en_attente') {
            throw new \DomainException('Cette déclaration a déjà été traitée.');
        }
        $this->verifier($d->etablissement, $d->date_debut->toDateString(), $d->date_fin->toDateString(), $d->id);

        return DB::transaction(function () use ($d, $moderateur) {
            $garde = Garde::create([
                'etablissement_id' => $d->etablissement_id,
                'date_debut' => $d->date_debut,
                'date_fin' => $d->date_fin,
                'source' => 'Déclaration du responsable',
            ]);
            $d->forceFill(['statut' => 'validee', 'traite_par' => $moderateur->id, 'traite_at' => now(), 'garde_id' => $garde->id])->save();

            return $garde;
        });
    }

    public function rejeter(GardeDeclaration $d, User $moderateur, string $motif): void
    {
        if ($d->statut !== 'en_attente') {
            throw new \DomainException('Cette déclaration a déjà été traitée.');
        }
        $d->forceFill(['statut' => 'rejetee', 'motif_rejet' => $motif, 'traite_par' => $moderateur->id, 'traite_at' => now()])->save();
    }
}
