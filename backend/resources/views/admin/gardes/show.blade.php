@extends('admin.layout')
@section('titre', 'Lot de garde #'.$lot->id)

@section('contenu')
    <h1>{{ $lot->ville->nom }} · du {{ $lot->date_debut->format('d/m/Y') }} au {{ $lot->date_fin->format('d/m/Y') }}</h1>

    <div class="grille">
        <div class="kpi"><div class="valeur">{{ $lot->nb_valides }}</div><div class="libelle">Gardes prêtes à publier</div></div>
        <div class="kpi"><div class="valeur">{{ $lot->nb_erreurs }}</div><div class="libelle">Lignes ignorées (erreurs, doublons)</div></div>
        <div class="kpi"><div class="valeur">{{ ['publie' => 'Publié', 'apercu' => 'Aperçu', 'annule' => 'Annulé'][$lot->statut] }}</div>
            <div class="libelle">{{ $lot->fichier_nom }}</div></div>
    </div>

    <div class="actions" style="margin-bottom:16px">
        @if($lot->statut === 'apercu')
            <form method="post" action="{{ route('admin.gardes.publier', $lot) }}">@csrf
                <button class="btn" @disabled($lot->nb_valides === 0)>Publier {{ $lot->nb_valides }} garde(s)</button>
            </form>
            <form method="post" action="{{ route('admin.gardes.annuler', $lot) }}">@csrf
                <button class="btn secondaire">Abandonner cet import</button>
            </form>
        @elseif($lot->statut === 'publie')
            <form method="post" action="{{ route('admin.gardes.annuler', $lot) }}" onsubmit="return confirm('Retirer toutes les gardes publiées par ce lot ?')">@csrf
                <button class="btn danger">Retirer ce programme</button>
            </form>
        @endif
        <a class="btn secondaire" href="{{ route('admin.gardes.index') }}">Retour</a>
    </div>

    <table>
        <thead><tr><th>Ligne</th><th>Dans le fichier</th><th>Pharmacie reconnue</th><th>Période</th><th>Résultat</th></tr></thead>
        <tbody>
        @foreach($lot->lignes as $l)
            <tr class="{{ $l['statut'] }}">
                <td>{{ $l['ligne'] }}</td>
                <td>{{ $l['nom'] }} @if($l['commune'])<span class="muted">({{ $l['commune'] }})</span>@endif</td>
                <td>{{ $l['etablissement_nom'] ?? '—' }}</td>
                <td>@if($l['date_debut']){{ \Illuminate\Support\Carbon::parse($l['date_debut'])->format('d/m') }} → {{ \Illuminate\Support\Carbon::parse($l['date_fin'])->format('d/m/Y') }}@endif</td>
                <td>
                    @if($l['statut'] === 'ok')
                        <span class="pastille vert">OK</span>
                    @else
                        <span class="pastille {{ $l['statut'] === 'erreur' ? 'rouge' : 'orange' }}">{{ $l['statut'] }}</span>
                        {{ $l['message'] }}
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
