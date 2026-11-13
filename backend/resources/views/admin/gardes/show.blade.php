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

    @if($flou)
        @php($aVerifier = collect($lot->lignes)->where('statut', 'a_verifier')->count())
        @if($lot->statut === 'apercu')
            <div class="carte">
                <p>Texte rapproché des pharmacies de {{ $lot->ville->nom }}. <strong>Vérifiez chaque ligne</strong> : choisissez la pharmacie
                    (liste ou recherche) ou ignorez la ligne, puis enregistrez. Seules les lignes « OK » seront publiées ;
                    les lignes « à confirmer » ou non reconnues ne le sont jamais automatiquement.
                    @if($aVerifier) <span class="pastille orange">{{ $aVerifier }} à confirmer</span>@endif</p>
                <label style="display:inline-flex;gap:6px;align-items:center;font-weight:400"><input type="checkbox" id="masquer-inconnues"> Masquer les lignes non reconnues</label>
            </div>
            <datalist id="pharmacies-ville">
                @foreach($pharmacies as $p)<option value="{{ $p->nom }} ({{ $p->commune->nom ?? '' }}) #{{ $p->id }}">@endforeach
            </datalist>
            <form method="post" action="{{ route('admin.gardes.corriger', $lot) }}">@csrf
        @endif
        <table id="table-lignes">
            <thead><tr><th>Ligne</th><th>Dans le document</th><th>Confiance</th><th>Pharmacie</th><th>Résultat</th></tr></thead>
            <tbody>
            @foreach($lot->lignes as $l)
                @php($cands = $l['candidats'] ?? [])
                @php($sel = $l['etablissement_id'] ?? (($l['statut'] ?? '') === 'a_verifier' ? ($cands[0]['id'] ?? null) : null))
                <tr class="{{ $l['statut'] === 'a_verifier' ? 'doublon' : $l['statut'] }}" @if($l['statut'] === 'erreur' && ! $cands) data-inconnue="1" @endif>
                    <td>{{ $l['ligne'] }}</td>
                    <td>{{ $l['nom'] }}</td>
                    <td>@if(isset($l['score']))<span class="pastille {{ $l['score'] >= 85 ? 'vert' : ($l['score'] >= 60 ? 'orange' : 'rouge') }}">{{ $l['score'] }} %</span>@endif</td>
                    <td style="min-width:300px">
                        @if($lot->statut === 'apercu')
                            <select name="choix[{{ $l['ligne'] }}]" data-ligne="{{ $l['ligne'] }}">
                                <option value="">— Ignorer cette ligne —</option>
                                @foreach($cands as $c)
                                    <option value="{{ $c['id'] }}" @selected($sel == $c['id'])>{{ $c['nom'] }} ({{ $c['commune'] }}) — {{ $c['score'] }} %</option>
                                @endforeach
                                @if($sel && ! collect($cands)->contains('id', $sel))
                                    <option value="{{ $sel }}" selected>{{ $l['etablissement_nom'] }}</option>
                                @endif
                            </select>
                            <input type="text" list="pharmacies-ville" class="autre" data-ligne="{{ $l['ligne'] }}" placeholder="Autre pharmacie : tapez pour chercher" autocomplete="off" style="margin-top:4px">
                        @else
                            {{ $l['etablissement_nom'] ?? '—' }}
                        @endif
                    </td>
                    <td>
                        @if($l['statut'] === 'ok')
                            <span class="pastille vert">OK{{ ! empty($l['confirme']) ? ' (confirmée)' : '' }}</span>
                        @else
                            <span class="pastille {{ $l['statut'] === 'erreur' ? 'rouge' : 'orange' }}">{{ $l['statut'] === 'a_verifier' ? 'à confirmer' : $l['statut'] }}</span>
                            {{ $l['message'] }}
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($lot->statut === 'apercu')
            <div class="actions" style="margin-top:12px"><button class="btn">Enregistrer les correspondances</button></div>
            </form>
            <script>
            document.querySelectorAll('input.autre').forEach(function (inp) {
                inp.addEventListener('change', function () {
                    var m = inp.value.match(/#(\d+)\s*$/);
                    if (!m) { return; }
                    var sel = document.querySelector('select[data-ligne="' + inp.dataset.ligne + '"]');
                    var opt = Array.prototype.find.call(sel.options, function (o) { return o.value === m[1]; });
                    if (!opt) { opt = new Option(inp.value.replace(/\s*#\d+\s*$/, ''), m[1]); sel.add(opt); }
                    sel.value = m[1];
                    inp.value = '';
                });
            });
            document.getElementById('masquer-inconnues').addEventListener('change', function (e) {
                document.querySelectorAll('tr[data-inconnue]').forEach(function (tr) { tr.hidden = e.target.checked; });
            });
            </script>
        @endif
    @else
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
    @endif
@endsection
