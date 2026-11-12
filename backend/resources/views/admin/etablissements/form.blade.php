@extends('admin.layout')
@php($e = $etablissement)
@section('titre', $e->exists ? $e->nom : 'Nouvel établissement')

@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
@endpush

@section('contenu')
    <h1>{{ $e->exists ? $e->nom : 'Nouvel établissement' }}</h1>

    @if($e->exists)
        <p class="muted">
            Dernière vérification : {{ $e->verifie_at?->format('d/m/Y H:i') ?? 'jamais' }}
            · <form class="inline" method="post" action="{{ route('admin.etablissements.verifier', $e) }}">@csrf<button class="btn petit secondaire">Marquer vérifié aujourd'hui</button></form>
        </p>
    @endif

    <form method="post" action="{{ $e->exists ? route('admin.etablissements.update', $e) : route('admin.etablissements.store') }}" class="carte">
        @csrf
        @if($e->exists) @method('PUT') @endif

        <div class="ligne">
            <div>
                <label for="nom">Dénomination *</label>
                <input type="text" id="nom" name="nom" value="{{ old('nom', $e->nom) }}" required maxlength="200">
            </div>
            <div>
                <label for="categorie_id">Catégorie *</label>
                <select id="categorie_id" name="categorie_id" required>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('categorie_id', $e->categorie_id) == $c->id)>{{ $c->libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="commune_id">Commune *</label>
                <select id="commune_id" name="commune_id" required>
                    @foreach($villes as $v)
                        <optgroup label="{{ $v->nom }}">
                            @foreach($v->communes as $c)
                                <option value="{{ $c->id }}" @selected(old('commune_id', $e->commune_id) == $c->id)>{{ $c->nom }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="ligne">
            <div><label for="adresse">Adresse</label><input type="text" id="adresse" name="adresse" value="{{ old('adresse', $e->adresse) }}"></div>
            <div><label for="repere">Repère</label><input type="text" id="repere" name="repere" value="{{ old('repere', $e->repere) }}" placeholder="Ex. face à la mairie"></div>
        </div>

        <div class="ligne">
            <div><label for="tel1">Téléphone 1</label><input type="text" id="tel1" name="tel1" value="{{ old('tel1', $e->tel1) }}"></div>
            <div><label for="tel2">Téléphone 2</label><input type="text" id="tel2" name="tel2" value="{{ old('tel2', $e->tel2) }}"></div>
            <div><label for="horaires">Horaires</label><input type="text" id="horaires" name="horaires" value="{{ old('horaires', $e->horaires) }}" placeholder="Lun-Sam 8h-20h"></div>
        </div>

        <div class="cases" style="margin-top:12px">
            <label><input type="checkbox" name="h24" value="1" @checked(old('h24', $e->h24))> Ouvert 24 h/24</label>
            <label><input type="checkbox" name="premium" value="1" @checked(old('premium', $e->premium))> Fiche Premium (V2)</label>
            <label><input type="checkbox" name="marquer_verifie" value="1"> Informations vérifiées aujourd'hui</label>
        </div>

        <label for="description">Description</label>
        <textarea id="description" name="description" placeholder="Ex. pour un bureau FGA : hôpital d'accueil, prise en charge des accidentés">{{ old('description', $e->description) }}</textarea>

        <div class="ligne">
            <div><label for="tel_autres">Autres téléphones</label><input type="text" id="tel_autres" name="tel_autres" value="{{ old('tel_autres', $e->tel_autres) }}"></div>
            <div><label for="email">E-mail</label><input type="email" id="email" name="email" value="{{ old('email', $e->email) }}"></div>
            <div><label for="site_web">Site web</label><input type="text" id="site_web" name="site_web" value="{{ old('site_web', $e->site_web) }}"></div>
            <div><label for="responsable_nom">Responsable (pharmacien, médecin…)</label><input type="text" id="responsable_nom" name="responsable_nom" value="{{ old('responsable_nom', $e->responsable_nom) }}"></div>
        </div>

        <h2>Position GPS</h2>
        @php($precise = old('position_precise', $e->exists ? $e->position_precise : true))
        @if($e->exists && ! $e->position_precise)
            <div class="flash erreurs">
                <strong>Position approximative.</strong> Cet établissement vient d'un annuaire sans GPS : le marqueur est au centre de la commune.
                L'application n'affiche donc ni distance ni point exact. Placez le marqueur au bon endroit, puis cochez la case ci-dessous.
            </div>
        @endif
        <p class="muted">Cliquez sur la carte ou déplacez le marqueur.</p>
        <div id="carte"></div>
        <div class="cases" style="margin-top:10px">
            <label><input type="checkbox" name="position_precise" value="1" @checked($precise)> <strong>Position GPS vérifiée</strong> (emplacement exact de l'établissement)</label>
        </div>
        <div class="ligne">
            <div><label for="lat">Latitude *</label><input type="number" step="0.0000001" id="lat" name="lat" value="{{ old('lat', $e->lat) }}" required></div>
            <div><label for="lng">Longitude *</label><input type="number" step="0.0000001" id="lng" name="lng" value="{{ old('lng', $e->lng) }}" required></div>
        </div>

        <h2>Services</h2>
        @php($servicesActuels = old('services', $e->relationLoaded('services') ? $e->services->pluck('service')->all() : []))
        <div class="cases">
            @foreach($servicesProposes as $s)
                <label><input type="checkbox" name="services[]" value="{{ $s }}" @checked(in_array($s, $servicesActuels, true))> {{ $s }}</label>
            @endforeach
        </div>
        <label for="services_autres">Autres services (séparés par des virgules)</label>
        <input type="text" id="services_autres" name="services_autres"
               value="{{ old('services_autres', implode(', ', array_diff($servicesActuels, $servicesProposes))) }}">

        <h2>Assurances acceptées</h2>
        @php($assurancesActuelles = old('assurances', $e->relationLoaded('assurances') ? $e->assurances->pluck('id')->all() : []))
        <div class="cases">
            @foreach($assurances as $a)
                <label><input type="checkbox" name="assurances[]" value="{{ $a->id }}" @checked(in_array($a->id, array_map('intval', $assurancesActuelles), true))> {{ $a->nom }}</label>
            @endforeach
        </div>

        <label for="statut">Statut</label>
        <select id="statut" name="statut" style="max-width:220px">
            <option value="brouillon" @selected(old('statut', $e->statut) === 'brouillon')>Brouillon (invisible)</option>
            <option value="publie" @selected(old('statut', $e->statut) === 'publie')>Publié</option>
        </select>

        <div class="actions">
            <button class="btn">Enregistrer</button>
            <a class="btn secondaire" href="{{ route('admin.etablissements.index') }}">Retour</a>
        </div>
    </form>

    @if($e->exists && auth()->user()->isAdmin())
        <form method="post" action="{{ route('admin.etablissements.destroy', $e) }}" onsubmit="return confirm('Supprimer définitivement cet établissement et ses gardes ?')">
            @csrf @method('DELETE')
            <button class="btn danger petit">Supprimer</button>
        </form>
    @endif
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
        (function () {
            const lat = document.getElementById('lat'), lng = document.getElementById('lng');
            const depart = [parseFloat(lat.value) || 5.36, parseFloat(lng.value) || -4.0083];
            const carte = L.map('carte').setView(depart, 15);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; OpenStreetMap'
            }).addTo(carte);
            const marqueur = L.marker(depart, { draggable: true }).addTo(carte);
            const maj = (p) => { lat.value = p.lat.toFixed(7); lng.value = p.lng.toFixed(7); };
            marqueur.on('dragend', () => maj(marqueur.getLatLng()));
            carte.on('click', (ev) => { marqueur.setLatLng(ev.latlng); maj(ev.latlng); });
            [lat, lng].forEach(i => i.addEventListener('change', () => {
                const p = [parseFloat(lat.value), parseFloat(lng.value)];
                if (!isNaN(p[0]) && !isNaN(p[1])) { marqueur.setLatLng(p); carte.panTo(p); }
            }));
        })();
    </script>
@endpush
