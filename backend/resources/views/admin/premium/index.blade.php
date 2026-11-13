@extends('admin.layout')
@section('titre', 'Fiches Premium')

@section('contenu')
    <h1>Fiches Premium</h1>
    <p class="sous-titre">Photos, horaires détaillés, description longue et mise en avant « À la une » (hors urgences). Statistiques anonymes sur les 30 derniers jours.</p>

    <table>
        <thead><tr><th>Établissement</th><th>Abonnement</th><th>Vues</th><th>Appels</th><th>Itinéraires</th><th>Photos</th><th></th></tr></thead>
        <tbody>
        @forelse($premium as $e)
            @php($s = $resumes[$e->id] ?? ['vues' => 0, 'appels' => 0, 'itineraires' => 0])
            <tr>
                <td>{{ $e->nom }}<br><span class="muted">{{ $e->categorie->libelle ?? '' }} · {{ $e->commune->nom }} ({{ $e->commune->ville->nom }})</span></td>
                <td>
                    @if($e->estPremium())<span class="pastille vert">Actif</span>@else<span class="pastille rouge">Expiré</span>@endif
                    <div class="muted">{{ $e->premium_jusqu_au ? 'jusqu\'au '.$e->premium_jusqu_au->format('d/m/Y') : 'sans date de fin' }}</div>
                </td>
                <td>{{ $s['vues'] }}</td><td>{{ $s['appels'] }}</td><td>{{ $s['itineraires'] }}</td>
                <td>{{ $e->photos_count }}/{{ \App\Services\PhotosEtablissement::MAX }}</td>
                <td><a class="btn petit secondaire" href="{{ route('admin.premium.edit', $e) }}">Gérer</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Aucune fiche Premium. Recherchez un établissement ci-dessous pour en activer une.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $premium->links('pagination::simple-default') }}

    <h2>Activer Premium pour un établissement</h2>
    <form method="get" class="filtres">
        <input type="search" name="q" value="{{ $q }}" placeholder="Nom de l'établissement" required>
        <button class="btn secondaire">Rechercher</button>
    </form>
    @if($q !== '')
        <table>
            <tbody>
            @forelse($resultats as $e)
                <tr>
                    <td>{{ $e->nom }}<br><span class="muted">{{ $e->categorie->libelle ?? '' }} · {{ $e->commune->nom }} ({{ $e->commune->ville->nom }})</span></td>
                    <td>
                        <form method="post" action="{{ route('admin.premium.activer', $e) }}" class="inline">@csrf
                            <label class="muted">jusqu'au <input type="date" name="premium_jusqu_au" value="{{ now()->addYear()->toDateString() }}"></label>
                            <button class="btn petit">Activer Premium</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="muted">Aucun établissement (non Premium) ne correspond.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
@endsection
