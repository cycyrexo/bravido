@extends('admin.layout')
@section('titre', 'Programme de garde')

@section('contenu')
    <h1>Programme de garde</h1>
    <p class="sous-titre">Définissez la période et cochez les pharmacies de garde. Pour un programme complet,
        utilisez l'<a href="{{ route('admin.gardes.create') }}">import CSV ou Excel</a>.
        · <a href="{{ route('admin.gardes.index') }}">Historique des programmes</a></p>

    {{-- Changer de ville ou de période recharge la liste (cases pré-cochées selon les gardes existantes) --}}
    <form method="get" class="filtres">
        <div><label for="ville_id">Ville</label>
            <select id="ville_id" name="ville_id" onchange="this.form.submit()">
                @foreach($villes as $v)
                    <option value="{{ $v->id }}" @selected($ville?->id === $v->id)>{{ $v->nom }}</option>
                @endforeach
            </select></div>
        <div><label for="g_debut">Début</label><input type="date" id="g_debut" name="date_debut" value="{{ $debut->toDateString() }}"></div>
        <div><label for="g_fin">Fin</label><input type="date" id="g_fin" name="date_fin" value="{{ $fin->toDateString() }}"></div>
        <button class="btn secondaire">Afficher</button>
    </form>

    @if($ville)
        <form method="post" action="{{ route('admin.gardes.saisie.enregistrer') }}">
            @csrf
            <input type="hidden" name="ville_id" value="{{ $ville->id }}">
            <input type="hidden" name="date_debut" value="{{ $debut->toDateString() }}">
            <input type="hidden" name="date_fin" value="{{ $fin->toDateString() }}">

            <table>
                <thead><tr><th style="width:80px">De garde</th><th>Pharmacie</th><th>Commune</th><th>Téléphone</th></tr></thead>
                <tbody>
                @forelse($pharmacies as $p)
                    <tr>
                        <td><input type="checkbox" name="etablissements[]" value="{{ $p->id }}" @checked(in_array($p->id, $deGarde, true)) aria-label="{{ $p->nom }} de garde"></td>
                        <td>{{ $p->nom }}</td>
                        <td>{{ $p->commune->nom }}</td>
                        <td>{{ $p->tel1 }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Aucune pharmacie publiée dans cette ville.
                        <a href="{{ route('admin.etablissements.create') }}">Créer une pharmacie</a></td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="actions">
                <button class="btn" @disabled($pharmacies->isEmpty())>Publier le programme</button>
                <span class="muted">du {{ $debut->format('d/m/Y') }} au {{ $fin->format('d/m/Y') }} · {{ $ville->nom }}</span>
            </div>
        </form>
    @endif
@endsection
