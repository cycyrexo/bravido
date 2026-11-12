@extends('public.layout')
@section('titre', 'Invitation')

@section('contenu')
    <div class="carte">
        <h1 style="margin:0 0 8px;font-size:22px">{{ $complice->user->nomAffiche() }} vous invite</h1>
        <p>{{ $complice->user->nomAffiche() }} ({{ $complice->user->telephone }}) souhaite vous ajouter à son cercle de complices BRAVIDO.</p>
        <p>Si vous acceptez, vous recevrez un SMS ou une notification avec sa position <strong>uniquement s'il ou elle déclenche une alerte</strong>. Vous pourrez quitter ce cercle à tout moment.</p>
    </div>

    @if($errors->any())
        <p class="erreur">{{ $errors->first() }}</p>
    @endif

    @if($complice->statut === 'invite')
        <form method="post" action="{{ route('invitation.repondre', request()->route('token')) }}">
            @csrf
            <button class="btn vert" name="reponse" value="accepter">J'accepte</button>
            <button class="btn gris" name="reponse" value="refuser">Je refuse</button>
        </form>
    @else
        <div class="carte">
            <p>Vous avez déjà {{ $complice->statut === 'accepte' ? 'accepté' : 'refusé' }} cette invitation.</p>
            @if($complice->statut === 'accepte')
                <form method="post" action="{{ route('invitation.repondre', request()->route('token')) }}">
                    @csrf
                    <button class="btn gris" name="reponse" value="refuser">Quitter ce cercle</button>
                </form>
            @endif
        </div>
    @endif
@endsection
