@extends('admin.layout')
@section('titre', 'Programme de garde')

@section('contenu')
    <h1>Programme de garde <a class="btn petit" href="{{ route('admin.gardes.create') }}">+ Importer un programme</a></h1>

    <table>
        <thead><tr><th>Ville</th><th>Période</th><th>Fichier</th><th>Lignes valides</th><th>Erreurs / doublons</th><th>Statut</th><th>Par</th><th></th></tr></thead>
        <tbody>
        @forelse($lots as $lot)
            <tr>
                <td>{{ $lot->ville->nom }}</td>
                <td>du {{ $lot->date_debut->format('d/m/Y') }} au {{ $lot->date_fin->format('d/m/Y') }}</td>
                <td>{{ $lot->fichier_nom }}</td>
                <td>{{ $lot->nb_valides }}</td>
                <td>{{ $lot->nb_erreurs }}</td>
                <td>
                    <span class="pastille {{ ['publie' => 'vert', 'apercu' => 'orange', 'annule' => 'rouge'][$lot->statut] }}">
                        {{ ['publie' => 'publié', 'apercu' => 'aperçu', 'annule' => 'annulé'][$lot->statut] }}
                    </span>
                </td>
                <td class="muted">{{ $lot->auteur?->nomAffiche() }}<br>{{ $lot->created_at->format('d/m/Y H:i') }}</td>
                <td><a class="btn petit secondaire" href="{{ route('admin.gardes.show', $lot) }}">Ouvrir</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Aucun import pour l'instant.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $lots->links('pagination::simple-default') }}
@endsection
