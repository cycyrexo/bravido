@extends('admin.layout')
@section('titre', 'Fiche #'.$fiche->id)

@section('contenu')
    <h1>
        Fiche de {{ $fiche->type }} #{{ $fiche->id }}
        <span class="pastille {{ ['en_attente' => 'orange', 'validee' => 'vert', 'rejetee' => 'rouge'][$fiche->statut] }}">{{ $fiche->statut }}</span>
    </h1>

    <p class="muted">
        Soumise par {{ $fiche->auteur->nomAffiche() }} ({{ $fiche->auteur->telephone }}) le {{ $fiche->created_at->format('d/m/Y à H:i') }}
        @if($fiche->etablissement) · établissement : <a href="{{ route('admin.etablissements.edit', $fiche->etablissement) }}">{{ $fiche->etablissement->nom }}</a>@endif
        @if($fiche->traite_at) · traitée par {{ $fiche->traitePar?->nomAffiche() }} le {{ $fiche->traite_at->format('d/m/Y') }}@endif
    </p>

    @if($fiche->motif_rejet)
        <div class="flash erreurs">Motif du rejet : {{ $fiche->motif_rejet }}</div>
    @endif

    <h2>{{ $fiche->type === 'correction' ? 'Comparaison avant / après' : 'Informations transmises' }}</h2>
    <table>
        <thead><tr><th>Champ</th>@if($fiche->type === 'correction')<th>Actuellement</th>@endif<th>Proposé</th></tr></thead>
        <tbody>
        @foreach($lignes as $l)
            <tr class="{{ $fiche->type === 'correction' && $l['modifie'] ? 'modifie' : '' }}">
                <td>{{ $l['libelle'] }}</td>
                @if($fiche->type === 'correction')<td>{{ $l['avant'] }}</td>@endif
                <td>{{ $l['apres'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if($fiche->pieces)
        <h2>Pièces jointes</h2>
        <ul>
            @foreach($fiche->pieces as $i => $piece)
                <li><a href="{{ route('admin.fiches.piece', [$fiche, $i]) }}">{{ $piece['nom'] }}</a></li>
            @endforeach
        </ul>
    @endif

    @if($fiche->statut === 'en_attente')
        <div class="ligne" style="margin-top:20px">
            <form method="post" action="{{ route('admin.fiches.valider', $fiche) }}" class="carte">
                @csrf
                <h2 style="margin-top:0">Valider</h2>
                @if($fiche->type === 'souscription')
                    @php($d = $fiche->donnees)
                    <p class="muted">Confirmez les champs indispensables. L'établissement sera publié et le demandeur en deviendra responsable.</p>
                    <label for="nom">Dénomination</label>
                    <input type="text" id="nom" name="nom" value="{{ old('nom', $d['nom'] ?? '') }}" required>
                    <label for="categorie_id">Catégorie</label>
                    <select id="categorie_id" name="categorie_id" required>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected(($d['categorie_code'] ?? null) === $c->code)>{{ $c->libelle }}</option>
                        @endforeach
                    </select>
                    <label for="commune_id">Commune</label>
                    <select id="commune_id" name="commune_id" required>
                        @foreach($villes as $v)
                            <optgroup label="{{ $v->nom }}">
                                @foreach($v->communes as $c)
                                    <option value="{{ $c->id }}" @selected(($d['commune_id'] ?? null) == $c->id)>{{ $c->nom }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <div class="ligne">
                        <div><label for="lat">Latitude</label><input type="number" step="0.0000001" id="lat" name="lat" value="{{ old('lat', $d['lat'] ?? '') }}" required></div>
                        <div><label for="lng">Longitude</label><input type="number" step="0.0000001" id="lng" name="lng" value="{{ old('lng', $d['lng'] ?? '') }}" required></div>
                    </div>
                    <p class="muted">Sans GPS transmis : relevez la position sur place ou sur une carte avant de valider.</p>
                @else
                    <p class="muted">Les modifications surlignées seront appliquées et la date de vérification mise à jour.</p>
                @endif
                <div class="actions"><button class="btn">Valider la fiche</button></div>
            </form>

            <form method="post" action="{{ route('admin.fiches.rejeter', $fiche) }}" class="carte">
                @csrf
                <h2 style="margin-top:0">Rejeter</h2>
                <label for="motif_rejet">Motif (envoyé au demandeur)</label>
                <textarea id="motif_rejet" name="motif_rejet" required>{{ old('motif_rejet') }}</textarea>
                <div class="actions"><button class="btn danger">Rejeter</button></div>
            </form>
        </div>
    @endif
@endsection
