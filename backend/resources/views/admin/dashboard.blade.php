@extends('admin.layout')
@section('titre', 'Tableau de bord')

@section('contenu')
    <h1>Tableau de bord</h1>
    <p class="sous-titre">Vue d'ensemble · {{ $villes }}</p>

    @if($villesSansGarde->isNotEmpty())
        <div class="flash erreurs">
            <strong>Aucune pharmacie de garde en cours</strong> pour :
            {{ $villesSansGarde->pluck('nom')->implode(', ') }}.
            <a href="{{ route('admin.gardes.saisie') }}">Saisir le programme de garde</a>
        </div>
    @endif

    <div class="grille" style="grid-template-columns: repeat(3, minmax(160px, 1fr)); max-width: 760px">
        @foreach($cartes as $carte)
            <a class="kpi" href="{{ $carte['lien'] }}" style="text-decoration:none;color:inherit">
                <div class="valeur {{ $carte['couleur'] ?? '' }}">{{ $carte['valeur'] }}</div>
                <div class="libelle">{{ $carte['libelle'] }}</div>
            </a>
        @endforeach
    </div>

    <div class="ligne" style="margin-top:24px">
        <div class="carte">
            <h2 style="margin-top:0">Fiches en attente</h2>
            @forelse($fiches as $fiche)
                <p><a href="{{ route('admin.fiches.show', $fiche) }}">{{ $fiche->donnees['nom'] ?? 'Correction #'.$fiche->id }}</a>
                    <span class="pastille">{{ $fiche->type }}</span><br>
                    <span class="muted">{{ $fiche->auteur->nomAffiche() }} · {{ $fiche->created_at->diffForHumans() }}</span></p>
            @empty
                <p class="muted">Aucune fiche en attente.</p>
            @endforelse
        </div>
        <div class="carte">
            <h2 style="margin-top:0">Nouveaux messages</h2>
            @forelse($messages as $message)
                <p><a href="{{ route('admin.messages.show', $message) }}">{{ \Illuminate\Support\Str::limit($message->texte, 80) }}</a>
                    <span class="pastille">{{ $message->type }}</span><br>
                    <span class="muted">{{ $message->user->nomAffiche() }} · {{ $message->created_at->diffForHumans() }}</span></p>
            @empty
                <p class="muted">Aucun nouveau message.</p>
            @endforelse
        </div>
    </div>
@endsection
