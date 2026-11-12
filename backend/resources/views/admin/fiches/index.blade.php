@extends('admin.layout')
@section('titre', 'Fiches')

@section('contenu')
    <h1>Fiches de souscription et de correction</h1>

    <div class="actions" style="margin:0 0 16px">
        @foreach(['en_attente' => 'En attente', 'validee' => 'Validées', 'rejetee' => 'Rejetées'] as $cle => $libelle)
            <a class="btn petit {{ $statut === $cle ? '' : 'secondaire' }}" href="{{ route('admin.fiches.index', ['statut' => $cle]) }}">{{ $libelle }}</a>
        @endforeach
    </div>

    <table>
        <thead><tr><th>Type</th><th>Établissement</th><th>Soumise par</th><th>Date</th><th>Pièces</th><th></th></tr></thead>
        <tbody>
        @forelse($fiches as $fiche)
            <tr>
                <td><span class="pastille {{ $fiche->type === 'souscription' ? 'vert' : 'orange' }}">{{ $fiche->type }}</span></td>
                <td>{{ $fiche->donnees['nom'] ?? $fiche->etablissement?->nom ?? '—' }}</td>
                <td>{{ $fiche->auteur->nomAffiche() }}<br><span class="muted">{{ $fiche->auteur->telephone }}</span></td>
                <td>{{ $fiche->created_at->format('d/m/Y H:i') }}</td>
                <td>{{ count($fiche->pieces ?? []) }}</td>
                <td><a class="btn petit secondaire" href="{{ route('admin.fiches.show', $fiche) }}">Examiner</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Aucune fiche.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $fiches->links('pagination::simple-default') }}
@endsection
