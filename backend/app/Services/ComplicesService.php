<?php

namespace App\Services;

use App\Models\Complice;
use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * URG-01 : cercle de 7 complices maximum, avec consentement explicite du complice.
 * La limite est vérifiée sous verrou (SELECT ... FOR UPDATE) pour résister aux requêtes concurrentes.
 */
class ComplicesService
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly SmsSender $sms,
    ) {}

    public function inviter(User $user, string $nom, string $telephone): Complice
    {
        if ($telephone === $user->telephone) {
            throw ValidationException::withMessages(['telephone' => 'Vous ne pouvez pas être votre propre complice.']);
        }

        $max = config('bravido.complices_max');

        $complice = DB::transaction(function () use ($user, $nom, $telephone, $max) {
            $existants = Complice::where('user_id', $user->id)->lockForUpdate()->get();
            $existant = $existants->firstWhere('telephone', $telephone);

            if ($existant && $existant->statut !== 'refuse') {
                throw ValidationException::withMessages(['telephone' => 'Ce numéro fait déjà partie de vos complices.']);
            }

            $actifs = $existants->where('statut', '!=', 'refuse');
            if ($actifs->count() >= $max) {
                throw ValidationException::withMessages([
                    'telephone' => "Vous avez déjà {$max} complices. Retirez-en un pour en inviter un autre.",
                ]);
            }

            $complice = $existant ?? new Complice;
            $complice->fill([
                'nom' => $nom,
                'telephone' => $telephone,
                'ordre' => ($actifs->max('ordre') ?? 0) + 1,
            ]);
            $complice->user_id = $user->id;
            $complice->statut = 'invite';
            $complice->repondu_at = null;
            $complice->invitation_token = Str::random(48);
            $complice->complice_user_id = User::where('telephone', $telephone)->value('id');
            $complice->save();

            return $complice;
        });

        $nomUser = $user->nomAffiche();
        $pushOk = $this->notifier->push(
            $complice->compliceUser,
            'Invitation BRAVIDO',
            "{$nomUser} vous invite à être son complice en cas d'urgence.",
            ['type' => 'invitation', 'complice_id' => $complice->id]
        );

        if (! $pushOk) {
            $this->sms->envoyer(
                $telephone,
                "BRAVIDO : {$nomUser} ({$user->telephone}) souhaite vous prévenir en cas d'urgence. "
                .'Acceptez ou refusez ici : '.route('invitation.show', $complice->invitation_token)
            );
        }

        return $complice;
    }

    public function repondre(Complice $complice, bool $accepte, ?User $repondant = null): Complice
    {
        $max = config('bravido.complices_max');

        $complice = DB::transaction(function () use ($complice, $accepte, $repondant, $max) {
            $cercle = Complice::where('user_id', $complice->user_id)->lockForUpdate()->get();
            $complice = $cercle->firstWhere('id', $complice->id);

            if ($accepte) {
                $acceptes = $cercle->where('statut', 'accepte')->where('id', '!=', $complice->id)->count();
                if ($acceptes >= $max) {
                    throw ValidationException::withMessages([
                        'complice' => 'Le cercle de complices de cette personne est déjà complet.',
                    ]);
                }
            }

            $complice->statut = $accepte ? 'accepte' : 'refuse';
            $complice->repondu_at = now();
            if ($repondant) {
                $complice->complice_user_id = $repondant->id;
            }
            $complice->save();

            return $complice;
        });

        $this->notifier->push(
            $complice->user,
            'BRAVIDO',
            $accepte
                ? "{$complice->nom} a accepté d'être votre complice."
                : "{$complice->nom} a décliné votre invitation.",
            ['type' => 'invitation_reponse', 'complice_id' => $complice->id]
        );

        return $complice;
    }
}
