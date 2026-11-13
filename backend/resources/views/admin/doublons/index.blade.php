@extends('admin.layout')
@section('titre', 'Doublons')

@section('contenu')
    <h1>Doublons <span class="pastille orange">{{ $total }} groupe(s)</span></h1>
    <p class="sous-titre">Fiches de la même ville ayant le même nom (sans accents ni « Pharmacie », « Cabinet »…) ou le même téléphone principal.
        Les différences sont surlignées. Choisissez la fiche à conserver : les champs vides sont complétés, les services, assurances, favoris et gardes sont repris, les autres fiches sont supprimées.</p>
    @include('admin.doublons._onglets')

    <form class="filtres" method="get">
        <select name="ville_id" onchange="this.form.submit()">
            <option value="">Toutes villes</option>
            @foreach($villes as $v)<option value="{{ $v->id }}" @selected(($f['ville_id'] ?? '') == $v->id)>{{ $v->nom }}</option>@endforeach
        </select>
        <select name="motif" onchange="this.form.submit()">
            <option value="">Nom ou téléphone</option>
            <option value="nom" @selected(($f['motif'] ?? '') === 'nom')>Même nom</option>
            <option value="tel" @selected(($f['motif'] ?? '') === 'tel')>Même téléphone</option>
        </select>
        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="meme_commune" value="1" onchange="this.form.submit()" @checked(! empty($f['meme_commune']))> Même commune seulement</label>
    </form>

    @forelse($groupes as $g)
        <form method="post" class="carte groupe-doublon" action="{{ route('admin.doublons.fusionner') }}">
            @csrf
            <div class="geo-entete">
                <div>
                    @foreach($g['motifs'] as $m)<span class="pastille orange">{{ $m === 'nom' ? 'Même nom' : 'Même téléphone' }}</span>@endforeach
                    <span class="pastille {{ $g['meme_commune'] ? 'rouge' : '' }}">{{ $g['meme_commune'] ? 'Même commune : très probable' : 'Communes différentes : à vérifier' }}</span>
                    <span class="muted">{{ count($g['ids']) }} fiches</span>
                </div>
            </div>
            <div class="defile">
            <table class="comparaison">
                <thead>
                <tr>
                    <th></th>
                    @foreach($g['fiches'] as $e)
                        <th>
                            <label class="choix"><input type="radio" name="maitre_id" value="{{ $e->id }}" @checked($e->id === $g['conseille']) required> Conserver</label>
                            <label class="choix"><input type="checkbox" name="ids[]" value="{{ $e->id }}" checked> Inclure</label>
                            <a class="muted" href="{{ route('admin.etablissements.edit', $e) }}" target="_blank" rel="noopener">#{{ $e->id }}</a>
                        </th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach($g['lignes'] as $l)
                    <tr class="{{ $l['diff'] ? 'diff' : '' }}">
                        <th scope="row">{{ $l['libelle'] }}</th>
                        @foreach($l['valeurs'] as $v)<td>{{ $v !== '' ? $v : '—' }}</td>@endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            <div class="actions">
                @if(auth()->user()->isAdmin())
                    <button class="btn" onclick="return confirm('Fusionner les fiches cochées dans la fiche à conserver ? Les autres seront supprimées (action journalisée).')">Fusionner</button>
                @endif
                <button class="btn secondaire" formaction="{{ route('admin.doublons.ignorer') }}" formnovalidate>Pas un doublon</button>
                <span class="muted">Fiche présélectionnée : la plus complète (GPS vérifié, publiée, services…).</span>
            </div>
        </form>
    @empty
        <div class="carte"><p>Aucun doublon probable avec ces filtres.</p></div>
    @endforelse

    {{ $pagination->links() }}
@endsection
