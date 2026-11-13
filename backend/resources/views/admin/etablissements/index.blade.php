@extends('admin.layout')
@section('titre', 'Établissements')

@section('contenu')
    <h1>Établissements <a class="btn petit" href="{{ route('admin.etablissements.create') }}">+ Nouveau</a></h1>

    <form class="filtres" method="get">
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}" placeholder="Rechercher un nom">
        <select name="categorie_id">
            <option value="">Toutes catégories</option>
            @foreach($categories as $c)
                <option value="{{ $c->id }}" @selected(($f['categorie_id'] ?? '') == $c->id)>{{ $c->libelle }}</option>
            @endforeach
        </select>
        <select name="ville_id">
            <option value="">Toutes villes</option>
            @foreach($villes as $v)
                <option value="{{ $v->id }}" @selected(($f['ville_id'] ?? '') == $v->id)>{{ $v->nom }}</option>
            @endforeach
        </select>
        <select name="statut">
            <option value="">Tous statuts</option>
            <option value="publie" @selected(($f['statut'] ?? '') === 'publie')>Publié</option>
            <option value="brouillon" @selected(($f['statut'] ?? '') === 'brouillon')>Brouillon</option>
        </select>
        <select name="position">
            <option value="">Toutes positions</option>
            <option value="approx" @selected(($f['position'] ?? '') === 'approx')>À géolocaliser</option>
            <option value="precise" @selected(($f['position'] ?? '') === 'precise')>GPS vérifié</option>
        </select>
        <button class="btn secondaire">Filtrer</button>
    </form>

    @if($aGeolocaliser > 0)
        <p class="muted">
            <span class="pastille orange">{{ number_format($aGeolocaliser, 0, ',', ' ') }} à géolocaliser</span>
            Ces établissements n'ont qu'une position approximative (centre de la commune) : l'application ne leur affiche pas de distance.
            <a href="{{ route('admin.geolocalisation.index') }}" class="btn petit">Ouvrir l'assistant de géolocalisation</a>
            <a href="{{ route('admin.etablissements.index', ['position' => 'approx']) }}">Voir la liste</a>
        </p>
    @endif

    <table>
        <thead><tr><th>Nom</th><th>Catégorie</th><th>Commune</th><th>Téléphone</th><th>Statut</th><th>Vérifié</th><th></th></tr></thead>
        <tbody>
        @forelse($etablissements as $e)
            <tr>
                <td><a href="{{ route('admin.etablissements.edit', $e) }}">{{ $e->nom }}</a>@if($e->h24) <span class="pastille vert">24 h/24</span>@endif
                    @if(! $e->position_precise) <span class="pastille orange">GPS à préciser</span>@endif</td>
                <td>{{ $e->categorie->libelle }}</td>
                <td>{{ $e->commune->nom }}, {{ $e->commune->ville->nom }}</td>
                <td>{{ $e->tel1 }}</td>
                <td><span class="pastille {{ $e->statut === 'publie' ? 'vert' : '' }}">{{ $e->statut }}</span></td>
                <td>
                    @if($e->verifie_at)
                        <span class="{{ $e->verifie_at->lt(now()->subMonths(6)) ? 'pastille orange' : 'muted' }}">{{ $e->verifie_at->format('d/m/Y') }}</span>
                    @else
                        <span class="pastille rouge">jamais</span>
                    @endif
                </td>
                <td>
                    <form class="inline" method="post" action="{{ route('admin.etablissements.statut', $e) }}">@csrf
                        <button class="btn petit secondaire">{{ $e->statut === 'publie' ? 'Dépublier' : 'Publier' }}</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Aucun établissement.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $etablissements->links('pagination::simple-default') }}
    <p class="muted">{{ $etablissements->total() }} établissement(s).</p>
@endsection
