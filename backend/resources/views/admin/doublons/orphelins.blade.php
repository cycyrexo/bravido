@extends('admin.layout')
@section('titre', 'Brouillons orphelins')

@section('contenu')
    <h1>Brouillons orphelins <span class="pastille orange">{{ $orphelins->total() }}</span></h1>
    <p class="sous-titre">Blocs du PDF dont le nom n'a pas été reconnu : « (nom à compléter — page N) ». Ils portent souvent les services d'un hôpital ou d'une clinique déjà listé.
        Recherchez la fiche cible dans la même commune puis fusionnez : les services, numéros et champs utiles sont repris, le brouillon est supprimé.</p>
    @include('admin.doublons._onglets')

    <form class="filtres" method="get">
        <select name="ville_id" onchange="this.form.submit()">
            <option value="">Toutes villes</option>
            @foreach($villes as $v)<option value="{{ $v->id }}" @selected(($f['ville_id'] ?? '') == $v->id)>{{ $v->nom }}</option>@endforeach
        </select>
    </form>

    @forelse($orphelins as $e)
        <form method="post" action="{{ route('admin.doublons.fusionner') }}" class="carte orphelin" data-commune="{{ $e->commune_id }}" data-cibles="{{ route('admin.doublons.cibles') }}">
            @csrf
            <input type="hidden" name="ids[]" value="{{ $e->id }}">
            <input type="hidden" name="maitre_id" value="" data-role="maitre">
            <div class="geo-entete">
                <div>
                    <strong>{{ $e->nom }}</strong>
                    <span class="pastille">PDF page {{ \App\Services\Doublons::pagePdf($e) ?? '?' }}</span>
                    <div class="muted">{{ $e->categorie->libelle ?? '' }} · {{ $e->commune->nom }} ({{ $e->commune->ville->nom }}) · <a href="{{ route('admin.etablissements.edit', $e) }}" target="_blank" rel="noopener">Ouvrir</a></div>
                    @if($e->tel1)<div class="muted">Tél. {{ $e->tel1 }}@if($e->tel2) · {{ $e->tel2 }}@endif @if($e->tel_autres)· {{ $e->tel_autres }}@endif</div>@endif
                    @if($e->adresse || $e->repere)<div class="muted">{{ $e->adresse ?: $e->repere }}</div>@endif
                    @if($e->services->isNotEmpty())<div class="muted">Services : {{ $e->services->pluck('service')->implode(', ') }}</div>@endif
                </div>
                <div class="cible">
                    <input type="search" data-role="recherche" placeholder="Chercher dans {{ $e->commune->nom }}…" autocomplete="off">
                    <ul class="resultats" data-role="resultats" hidden></ul>
                    <div class="actions">
                        @if(auth()->user()->isAdmin())
                            <button class="btn" data-role="fusion" disabled onclick="return confirm('Fusionner ce brouillon dans la fiche choisie ? Le brouillon sera supprimé.')">Fusionner avec…</button>
                        @endif
                    </div>
                </div>
            </div>
        </form>
    @empty
        <div class="carte"><p>Aucun brouillon orphelin.</p></div>
    @endforelse

    {{ $orphelins->links() }}
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.orphelin').forEach(function (f) {
        const champ = f.querySelector('[data-role=recherche]');
        const liste = f.querySelector('[data-role=resultats]');
        const bouton = f.querySelector('[data-role=fusion]');
        const maitre = f.querySelector('[data-role=maitre]');
        let minuteur = null;
        const chercher = function () {
            const url = f.dataset.cibles + '?commune_id=' + encodeURIComponent(f.dataset.commune) + '&q=' + encodeURIComponent(champ.value);
            fetch(url, { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(function (rows) {
                liste.innerHTML = '';
                rows.forEach(function (r) {
                    const li = document.createElement('li');
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = r.nom + ' — ' + r.categorie + (r.statut === 'brouillon' ? ' (brouillon)' : '');
                    b.addEventListener('click', function () {
                        maitre.value = r.id;
                        champ.value = r.nom;
                        liste.hidden = true;
                        if (bouton) { bouton.disabled = false; bouton.textContent = 'Fusionner avec « ' + r.nom + ' »'; }
                    });
                    li.appendChild(b); liste.appendChild(li);
                });
                if (!rows.length) { liste.innerHTML = '<li class="muted">Aucun résultat</li>'; }
                liste.hidden = false;
            });
        };
        champ.addEventListener('focus', chercher);
        champ.addEventListener('input', function () {
            maitre.value = ''; if (bouton) { bouton.disabled = true; bouton.textContent = 'Fusionner avec…'; }
            clearTimeout(minuteur); minuteur = setTimeout(chercher, 250);
        });
        f.addEventListener('submit', function (ev) { if (!maitre.value) ev.preventDefault(); });
    });
})();
</script>
@endpush
