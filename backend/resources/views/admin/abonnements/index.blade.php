@extends('admin.layout')
@section('titre', 'Abonnements')

@section('contenu')
    <h1>Abonnements famille et paiements</h1>
    <p class="sous-titre">Infrastructure en bac à sable. Les alertes d'urgence ne dépendent jamais d'un abonnement : annuler ou laisser expirer un abonnement n'affecte aucune alerte.</p>

    <div class="actions" style="margin:0 0 16px">
        @foreach(['abonnements' => 'Abonnements', 'paiements' => 'Paiements', 'plans' => 'Plans'] as $cle => $libelle)
            <a class="btn petit {{ $onglet === $cle ? '' : 'secondaire' }}" href="{{ route('admin.abonnements.index', ['onglet' => $cle]) }}">{{ $libelle }}</a>
        @endforeach
    </div>

    @if($onglet !== 'plans')
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end">
            <input type="hidden" name="onglet" value="{{ $onglet }}">
            <div><label style="margin:0">Téléphone</label><input name="q" value="{{ $f['q'] ?? '' }}" placeholder="07…"></div>
            <div><label style="margin:0">Statut</label>
                <select name="statut"><option value="">Tous</option>
                    @foreach(($onglet === 'paiements' ? ['en_attente', 'reussi', 'echoue'] : ['actif', 'expire', 'annule']) as $s)
                        <option value="{{ $s }}" @selected(($f['statut'] ?? '') === $s)>{{ $s }}</option>
                    @endforeach
                </select></div>
            <div><label style="margin:0">Plan</label>
                <select name="plan"><option value="">Tous</option>@foreach($plans as $p)<option value="{{ $p->id }}" @selected(($f['plan'] ?? '') == $p->id)>{{ $p->nom }}</option>@endforeach</select></div>
            @if($onglet === 'paiements')
                <div><label style="margin:0">Fournisseur</label>
                    <select name="fournisseur"><option value="">Tous</option>@foreach(['sandbox', 'orange_money', 'mtn_momo', 'wave'] as $v)<option value="{{ $v }}" @selected(($f['fournisseur'] ?? '') === $v)>{{ $v }}</option>@endforeach</select></div>
            @endif
            <button class="btn petit">Filtrer</button>
        </form>
    @endif

    @if($onglet === 'abonnements')
        <div class="carte">
            <h2 style="margin-top:0">Accorder un abonnement</h2>
            <form method="post" action="{{ route('admin.abonnements.accorder') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">@csrf
                <div><label style="margin:0">Téléphone de l'utilisateur</label><input name="telephone" required value="{{ old('telephone') }}"></div>
                <div><label style="margin:0">Plan</label><select name="plan_id">@foreach($plans as $p)<option value="{{ $p->id }}">{{ $p->nom }}</option>@endforeach</select></div>
                <div><label style="margin:0">Durée (jours, facultatif)</label><input type="number" name="jours" min="1" style="width:110px"></div>
                <button class="btn petit">Accorder</button>
            </form>
            @error('telephone')<p style="color:var(--rouge)">{{ $message }}</p>@enderror
        </div>
        <table>
            <thead><tr><th>#</th><th>Titulaire</th><th>Plan</th><th>Membres</th><th>Origine</th><th>Fin</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            @forelse($abonnements as $a)
                <tr>
                    <td>{{ $a->id }}</td>
                    <td>{{ $a->proprietaire->nomAffiche() }}<br><span class="muted">{{ $a->proprietaire->telephone }}</span></td>
                    <td>{{ $a->plan->nom }}</td>
                    <td>{{ $a->membres_count }}</td>
                    <td>{{ $a->origine }}</td>
                    <td>{{ $a->fin->format('d/m/Y') }}</td>
                    <td><span class="pastille {{ ['actif' => 'vert', 'annule' => 'rouge'][$a->statut] ?? '' }}">{{ $a->statut }}</span></td>
                    <td>@if($a->statut === 'actif')<form method="post" action="{{ route('admin.abonnements.annuler', $a) }}" onsubmit="return confirm('Annuler cet abonnement ?')">@csrf<button class="btn petit secondaire">Annuler</button></form>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Aucun abonnement.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $abonnements->links('pagination::simple-default') }}
    @elseif($onglet === 'paiements')
        <table>
            <thead><tr><th>Référence</th><th>Utilisateur</th><th>Plan</th><th>Montant</th><th>Fournisseur</th><th>Statut</th><th>Date</th></tr></thead>
            <tbody>
            @forelse($paiements as $p)
                <tr>
                    <td>{{ $p->reference }}</td>
                    <td>{{ $p->user->nomAffiche() }}<br><span class="muted">{{ $p->user->telephone }}</span></td>
                    <td>{{ $p->plan->nom }}</td>
                    <td>{{ number_format($p->montant, 0, ',', ' ') }} {{ $p->devise }}</td>
                    <td>{{ $p->fournisseur }}</td>
                    <td><span class="pastille {{ ['reussi' => 'vert', 'echoue' => 'rouge'][$p->statut] ?? 'orange' }}">{{ $p->statut }}</span></td>
                    <td>{{ $p->created_at->format('d/m/Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Aucun paiement.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $paiements->links('pagination::simple-default') }}
    @else
        <table>
            <thead><tr><th>Code</th><th>Nom</th><th>Prix (XOF)</th><th>Durée</th><th>Profils</th><th>État</th><th></th></tr></thead>
            <tbody>
            @foreach($plans as $p)
                <tr>
                    <td>{{ $p->code }}</td><td>{{ $p->nom }}</td><td>{{ number_format($p->prix_xof, 0, ',', ' ') }}</td>
                    <td>{{ $p->duree_jours }} j</td><td>{{ $p->profils_max }}</td>
                    <td>@if($p->actif)<span class="pastille vert">actif</span>@else<span class="pastille">inactif</span>@endif
                        @if($p->a_confirmer)<span class="pastille orange">à confirmer</span>@endif</td>
                    <td><a class="btn petit secondaire" href="{{ route('admin.abonnements.index', ['onglet' => 'plans', 'edit' => $p->id]) }}">Modifier</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="carte" style="margin-top:20px;max-width:640px">
            <h2 style="margin-top:0">{{ $edition ? 'Modifier le plan « '.$edition->nom.' »' : 'Nouveau plan' }}</h2>
            <form method="post" action="{{ $edition ? route('admin.abonnements.plans.maj', $edition) : route('admin.abonnements.plans.creer') }}">@csrf
                <label>Code (minuscules, chiffres, _)</label><input name="code" required value="{{ old('code', $edition?->code) }}">
                <label>Nom</label><input name="nom" required value="{{ old('nom', $edition?->nom) }}">
                <label>Prix (XOF)</label><input type="number" name="prix_xof" min="0" required value="{{ old('prix_xof', $edition?->prix_xof) }}">
                <label>Durée (jours)</label><input type="number" name="duree_jours" min="1" required value="{{ old('duree_jours', $edition?->duree_jours ?? 30) }}">
                <label>Profils au total (titulaire compris)</label><input type="number" name="profils_max" min="1" max="20" required value="{{ old('profils_max', $edition?->profils_max ?? 4) }}">
                <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="actif" value="1" @checked(old('actif', $edition?->actif ?? true))> Proposé aux utilisateurs</label>
                <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="a_confirmer" value="1" @checked(old('a_confirmer', $edition?->a_confirmer ?? true))> Prix et conditions à confirmer</label>
                @if($errors->any())<p style="color:var(--rouge)">{{ $errors->first() }}</p>@endif
                <button class="btn" style="margin-top:12px">Enregistrer</button>
            </form>
        </div>
    @endif
@endsection
