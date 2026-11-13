@extends('admin.layout')
@section('titre', 'Déclarations de garde')

@section('contenu')
    <h1>Déclarations de garde des responsables</h1>
    <p class="sous-titre">Une fausse information de garde peut mettre une personne en danger : vérifiez la période (appel à la pharmacie, programme officiel) avant de publier.</p>

    <div class="actions" style="margin:0 0 16px">
        @foreach(['en_attente' => 'En attente', 'validee' => 'Validées', 'rejetee' => 'Rejetées'] as $cle => $libelle)
            <a class="btn petit {{ $statut === $cle ? '' : 'secondaire' }}" href="{{ route('admin.gardes.declarations.index', ['statut' => $cle]) }}">{{ $libelle }}</a>
        @endforeach
    </div>

    <table>
        <thead><tr><th>Pharmacie</th><th>Période</th><th>Déclarée par</th><th>Contexte</th><th>Date</th><th></th></tr></thead>
        <tbody>
        @forelse($declarations as $d)
            <tr>
                <td><a href="{{ route('admin.etablissements.edit', $d->etablissement) }}">{{ $d->etablissement->nom }}</a><br>
                    <span class="muted">{{ $d->etablissement->commune->nom }} ({{ $d->etablissement->commune->ville->nom }})</span></td>
                <td>du {{ $d->date_debut->format('d/m/Y') }} au {{ $d->date_fin->format('d/m/Y') }}</td>
                <td>{{ $d->auteur->nomAffiche() }}<br><span class="muted">{{ $d->auteur->telephone }}</span></td>
                <td class="muted">{{ $autres[$d->id] }} autre(s) pharmacie(s) déjà de garde sur la période dans la ville</td>
                <td>{{ $d->created_at->format('d/m/Y H:i') }}</td>
                <td>
                    @if($d->statut === 'en_attente')
                        <form method="post" action="{{ route('admin.gardes.declarations.valider', $d) }}" class="inline">@csrf
                            <button class="btn petit">Valider</button></form>
                        <form method="post" action="{{ route('admin.gardes.declarations.rejeter', $d) }}" class="inline"
                              onsubmit="var m = prompt('Motif du rejet (envoyé au responsable) :'); if (!m) return false; this.motif_rejet.value = m;">@csrf
                            <input type="hidden" name="motif_rejet"><button class="btn petit danger">Rejeter</button></form>
                    @else
                        <span class="pastille {{ $d->statut === 'validee' ? 'vert' : 'rouge' }}">{{ $d->statut === 'validee' ? 'Publiée' : 'Rejetée' }}</span>
                        @if($d->motif_rejet)<div class="muted">{{ $d->motif_rejet }}</div>@endif
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Aucune déclaration.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $declarations->links('pagination::simple-default') }}
@endsection
