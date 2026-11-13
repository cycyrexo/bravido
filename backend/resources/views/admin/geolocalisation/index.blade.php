@extends('admin.layout')
@section('titre', 'À géolocaliser')

@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
@endpush

@section('contenu')
    <h1>À géolocaliser <span class="pastille orange" id="compteur">{{ number_format($restants, 0, ',', ' ') }} restant(s)</span></h1>
    <p class="sous-titre">
        Ces établissements n'ont que la position du centre de leur commune. Vérifiez le point proposé (OpenStreetMap) ou placez-le à la main,
        puis validez : l'application pourra alors afficher la distance.
        @if($nbIgnores > 0)
            · {{ $nbIgnores }} ignoré(s) pour cette session
            <form class="inline" method="post" action="{{ route('admin.geolocalisation.reinitialiser') }}">@csrf <button class="btn secondaire petit">Réinitialiser</button></form>
        @endif
    </p>

    <section id="propositions">
        <h2>Propositions des utilisateurs <span class="pastille {{ count($propositions) ? 'orange' : '' }}">{{ count($propositions) }}</span></h2>
        <p class="muted">Établissements dont au moins {{ \App\Services\PositionsUtilisateurs::SEUIL_UTILISATEURS }} utilisateurs distincts ont confirmé la position sur place, à moins de {{ (int) \App\Services\PositionsUtilisateurs::RAYON_GROUPE_M }} m les uns des autres.
            Le point affiché est la médiane des propositions ; rien n'est publié tant que vous n'avez pas validé.</p>
        @forelse($propositions as $p)
            <article class="carte prop-carte" data-lat="{{ $p['consensus']['lat'] }}" data-lng="{{ $p['consensus']['lng'] }}" data-ancien-lat="{{ $p['etab']->lat }}" data-ancien-lng="{{ $p['etab']->lng }}">
                <div class="geo-entete">
                    <div>
                        <strong>{{ $p['etab']->nom }}</strong>
                        <div class="muted">{{ $p['etab']->categorie->libelle ?? '' }} · {{ $p['etab']->commune->nom }} ({{ $p['etab']->commune->ville->nom }})</div>
                        <div>{{ $p['consensus']['nb'] }} utilisateur(s) concordant(s) sur {{ $p['consensus']['total'] }} proposition(s)</div>
                        <div class="muted">Point proposé : {{ number_format($p['consensus']['lat'], 6, '.', '') }}, {{ number_format($p['consensus']['lng'], 6, '.', '') }}</div>
                    </div>
                </div>
                <div class="geo-carte-map prop-map"></div>
                <div class="actions">
                    <form method="post" action="{{ route('admin.geolocalisation.propositions.valider', $p['etab']) }}" class="inline">@csrf
                        <button class="btn">Valider</button></form>
                    <form method="post" action="{{ route('admin.geolocalisation.propositions.rejeter', $p['etab']) }}" class="inline"
                          onsubmit="return confirm('Rejeter et supprimer ces propositions ?')">@csrf
                        <button class="btn secondaire">Rejeter</button></form>
                </div>
            </article>
        @empty
            <div class="carte"><p class="muted">Aucune position à valider pour le moment.</p></div>
        @endforelse
    </section>

    <form class="filtres" method="get">
        <select name="ville_id" onchange="this.form.elements.commune_id && (this.form.elements.commune_id.value=''); this.form.submit()">
            <option value="">Toutes villes</option>
            @foreach($villes as $v)
                <option value="{{ $v->id }}" @selected(($f['ville_id'] ?? '') == $v->id)>{{ $v->nom }}</option>
            @endforeach
        </select>
        <select name="commune_id" onchange="this.form.submit()">
            <option value="">Toutes communes</option>
            @foreach($communes as $c)
                <option value="{{ $c->id }}" @selected(($f['commune_id'] ?? '') == $c->id)>{{ $c->nom }}</option>
            @endforeach
        </select>
        <select name="categorie_id" onchange="this.form.submit()">
            <option value="">Toutes catégories</option>
            @foreach($categories as $c)
                <option value="{{ $c->id }}" @selected(($f['categorie_id'] ?? '') == $c->id)>{{ $c->libelle }}</option>
            @endforeach
        </select>
        <noscript><button class="btn secondaire">Filtrer</button></noscript>
    </form>

    <p class="muted geo-aide">
        Clavier : <kbd>J</kbd>/<kbd>K</kbd> fiche suivante/précédente · <kbd>1</kbd> <kbd>2</kbd> <kbd>3</kbd> choisir un candidat ·
        <kbd>M</kbd> placer à la main · <kbd>Entrée</kbd> valider · <kbd>I</kbd> ignorer
    </p>

    @forelse($etablissements as $e)
        <article class="carte geo-carte" tabindex="0" data-id="{{ $e->id }}"
                 data-lat="{{ $e->lat }}" data-lng="{{ $e->lng }}"
                 data-url-candidats="{{ route('admin.geolocalisation.candidats', $e) }}"
                 data-url-valider="{{ route('admin.geolocalisation.valider', $e) }}"
                 data-url-ignorer="{{ route('admin.geolocalisation.ignorer', $e) }}">
            <div class="geo-entete">
                <div>
                    <strong>{{ $e->nom }}</strong>
                    <span class="pastille {{ $e->statut === 'publie' ? 'vert' : '' }}">{{ $e->statut === 'publie' ? 'Publié' : 'Brouillon' }}</span>
                    <div class="muted">{{ $e->categorie->libelle ?? '' }} · {{ $e->commune->nom }} ({{ $e->commune->ville->nom }})</div>
                    @if($e->adresse || $e->repere)<div>{{ $e->adresse ?: $e->repere }}</div>@endif
                    @if($e->tel1)<div class="muted">Tél. {{ $e->tel1 }}@if($e->tel2) · {{ $e->tel2 }}@endif</div>@endif
                    <a class="muted" href="{{ route('admin.etablissements.edit', $e) }}" target="_blank" rel="noopener">Ouvrir la fiche</a>
                </div>
                <div class="geo-statut muted" data-role="statut">Chargement…</div>
            </div>
            <div class="geo-carte-map" data-role="map"></div>
            <ol class="geo-candidats" data-role="candidats"></ol>
            <div class="actions">
                <button type="button" class="btn" data-act="valider" disabled>Valider ce point</button>
                <button type="button" class="btn secondaire" data-act="manuel">Placer à la main</button>
                <button type="button" class="btn secondaire" data-act="ignorer">Ignorer</button>
            </div>
        </article>
    @empty
        <div class="carte">
            <p>Rien à géolocaliser avec ces filtres. @if($nbIgnores > 0) Des établissements ont été ignorés pour cette session. @endif</p>
        </div>
    @endforelse

    @if($etablissements->isNotEmpty())
        <p class="geo-fin" hidden id="suite">
            Lot terminé. <a class="btn" href="{{ request()->fullUrl() }}">Charger les suivants</a>
        </p>
    @endif
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
    document.querySelectorAll('.prop-carte').forEach(function (c) {
        if (typeof L === 'undefined') return;
        const pt = [parseFloat(c.dataset.lat), parseFloat(c.dataset.lng)];
        const carte = L.map(c.querySelector('.prop-map')).setView(pt, 17);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(carte);
        L.marker(pt).addTo(carte).bindTooltip('Point proposé (médiane)');
        L.circle(pt, { radius: 60, color: '#1f8a5b', weight: 1, fillOpacity: .1 }).addTo(carte);
    });
    </script>
    <script>
    (function () {
        const csrf = document.querySelector('meta[name=csrf-token]')?.content || @json(csrf_token());
        const cartes = Array.from(document.querySelectorAll('.geo-carte'));
        const compteur = document.getElementById('compteur');
        let actif = null;

        const post = (url, corps) => fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(corps || {}),
        }).then(async r => ({ ok: r.ok, data: await r.json().catch(() => ({})) }));

        const majCompteur = (n) => { if (typeof n === 'number') compteur.textContent = n.toLocaleString('fr-FR') + ' restant(s)'; };

        // File d'initialisation séquentielle : une requête Nominatim à la fois (1/s côté serveur)
        let file = Promise.resolve();
        const init = (c) => {
            if (c._init) return;
            c._init = true;
            c._etat = { point: null, manuel: false, choix: null, candidats: [] };
            const centre = [parseFloat(c.dataset.lat), parseFloat(c.dataset.lng)];
            const carte = L.map(c.querySelector('[data-role=map]')).setView(centre, 15);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(carte);
            L.circleMarker(centre, { radius: 7, color: '#6b7780', weight: 2, fillOpacity: .15 }).addTo(carte).bindTooltip('Centre de la commune');
            c._carte = carte;
            carte.on('click', (ev) => { if (c._etat.manuel) placer(c, ev.latlng); });
            file = file.then(() => charger(c));
        };

        const statut = (c, t) => { c.querySelector('[data-role=statut]').textContent = t; };

        async function charger(c) {
            statut(c, 'Recherche OpenStreetMap…');
            try {
                const r = await fetch(c.dataset.urlCandidats, { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                c._etat.candidats = d.candidats || [];
            } catch (e) { c._etat.candidats = []; }
            const liste = c.querySelector('[data-role=candidats]');
            liste.innerHTML = '';
            const cadre = [];
            c._etat.candidats.forEach((k, i) => {
                const icone = L.divIcon({ className: 'geo-num', html: String(i + 1), iconSize: [26, 26] });
                L.marker([k.lat, k.lng], { icon: icone }).addTo(c._carte).on('click', () => choisir(c, i));
                cadre.push([k.lat, k.lng]);
                const li = document.createElement('li');
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'geo-cand';
                b.textContent = (i + 1) + '. ' + k.libelle;
                if (k.distance_centre_m !== null) b.textContent += ' — à ' + (k.distance_centre_m / 1000).toFixed(1) + ' km du centre-ville';
                b.addEventListener('click', () => choisir(c, i));
                li.appendChild(b); liste.appendChild(li);
            });
            if (cadre.length) c._carte.fitBounds(cadre, { maxZoom: 17, padding: [30, 30] });
            statut(c, cadre.length ? cadre.length + ' candidat(s)' : 'Aucun candidat : placez le point à la main (M)');
            if (cadre.length === 1) choisir(c, 0);
        }

        function placer(c, ll) {
            const e = c._etat;
            e.point = { lat: ll.lat, lng: ll.lng };
            if (!c._sel) {
                c._sel = L.marker(ll, { draggable: true, zIndexOffset: 1000 }).addTo(c._carte);
                c._sel.on('dragend', () => { c._etat.point = c._sel.getLatLng(); c._etat.choix = null; majListe(c); });
            } else { c._sel.setLatLng(ll); }
            c.querySelector('[data-act=valider]').disabled = false;
        }

        function majListe(c) {
            c.querySelectorAll('.geo-cand').forEach((b, i) => b.classList.toggle('choisi', i === c._etat.choix));
        }

        function choisir(c, i) {
            const k = c._etat.candidats[i];
            if (!k) return;
            c._etat.choix = i; c._etat.manuel = false;
            placer(c, L.latLng(k.lat, k.lng));
            c._carte.panTo([k.lat, k.lng]);
            majListe(c);
        }

        function manuel(c) {
            c._etat.manuel = true;
            placer(c, c._etat.point ? L.latLng(c._etat.point.lat, c._etat.point.lng) : c._carte.getCenter());
            c._etat.choix = null; majListe(c);
            statut(c, 'Déplacez le repère ou cliquez sur la carte, puis validez.');
        }

        async function valider(c) {
            const p = c._etat.point;
            if (!p || c._occupe) return;
            c._occupe = true;
            const r = await post(c.dataset.urlValider, { lat: p.lat, lng: p.lng });
            c._occupe = false;
            if (!r.ok) { statut(c, r.data.message || 'Erreur : point refusé.'); return; }
            majCompteur(r.data.restants);
            terminer(c, 'Validé', 'fait');
        }

        async function ignorer(c) {
            if (c._occupe) return;
            c._occupe = true;
            const r = await post(c.dataset.urlIgnorer);
            c._occupe = false;
            if (!r.ok) return;
            majCompteur(r.data.restants);
            terminer(c, 'Ignoré', 'ignore');
        }

        function terminer(c, texte, classe) {
            c.classList.add(classe);
            c.querySelectorAll('button').forEach(b => b.disabled = true);
            statut(c, texte);
            const suivante = cartes.slice(cartes.indexOf(c) + 1).find(x => !x.classList.contains('fait') && !x.classList.contains('ignore'));
            if (suivante) activer(suivante); else document.getElementById('suite').hidden = false;
        }

        function activer(c, defiler = true) {
            if (!c) return;
            actif = c;
            cartes.forEach(x => x.classList.toggle('actif', x === c));
            init(c);
            if (defiler) c.scrollIntoView({ behavior: "smooth", block: "start" });
            c.focus({ preventScroll: true });
            if (c._carte) setTimeout(() => c._carte.invalidateSize(), 250);
        }

        cartes.forEach(c => {
            c.addEventListener('focusin', () => { if (actif !== c) { actif = c; cartes.forEach(x => x.classList.toggle('actif', x === c)); init(c); } });
            c.querySelector('[data-act=valider]').addEventListener('click', () => valider(c));
            c.querySelector('[data-act=manuel]').addEventListener('click', () => manuel(c));
            c.querySelector('[data-act=ignorer]').addEventListener('click', () => ignorer(c));
        });
        if ('IntersectionObserver' in window) {
            const obs = new IntersectionObserver((es) => es.forEach(en => { if (en.isIntersecting) init(en.target); }), { rootMargin: '150px' });
            cartes.forEach(c => obs.observe(c));
        } else { cartes.forEach(init); }

        document.addEventListener('keydown', (ev) => {
            if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
            const t = ev.target;
            if (t && (/^(INPUT|SELECT|TEXTAREA)$/.test(t.tagName) || t.isContentEditable)) return;
            const libres = cartes.filter(x => !x.classList.contains('fait') && !x.classList.contains('ignore'));
            const k = ev.key.toLowerCase();
            const pos = libres.indexOf(actif);
            if (k === 'j' || k === 'arrowdown' && t === actif) { ev.preventDefault(); activer(libres[Math.min(pos + 1, libres.length - 1)] || libres[0]); }
            else if (k === 'k' || k === 'arrowup' && t === actif) { ev.preventDefault(); activer(libres[Math.max(pos - 1, 0)]); }
            else if (!actif || actif.classList.contains('fait') || actif.classList.contains('ignore')) return;
            else if (['1', '2', '3'].includes(k)) { choisir(actif, parseInt(k, 10) - 1); }
            else if (k === 'm') { ev.preventDefault(); manuel(actif); }
            else if (k === 'i') { ignorer(actif); }
            else if (k === 'enter' && t.tagName !== 'BUTTON') { ev.preventDefault(); valider(actif); }
        });

        if (cartes[0]) activer(cartes[0], false);
    })();
    </script>
@endpush
