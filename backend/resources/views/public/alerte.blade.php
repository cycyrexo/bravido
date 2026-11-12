@extends('public.layout')
@section('titre', 'Alerte')

@push('head')
    @if($active)
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    @endif
@endpush

@section('contenu')
    @if($active)
        <div class="carte alerte">
            <h1 style="margin:0 0 8px;font-size:22px">{{ $alerte->user->nomAffiche() }} a besoin d'aide</h1>
            <p style="margin:0">Motif : <strong>{{ $alerte->libelleMotif() }}</strong> · depuis {{ $alerte->started_at->format('H:i') }}</p>
            @if($alerte->user->groupe_sanguin)<p class="muted" style="margin:4px 0 0">Groupe sanguin : {{ $alerte->user->groupe_sanguin }}</p>@endif
        </div>

        <div id="carte"></div>
        <p class="muted" id="maj">
            @if($alerte->dernierePosition)
                Position du {{ $alerte->dernierePosition->recorded_at->format('H:i:s') }}
                @if($alerte->dernierePosition->precision_m) (précision ± {{ $alerte->dernierePosition->precision_m }} m)@endif
            @else
                Position pas encore disponible.
            @endif
        </p>

        <a class="btn vert" href="tel:{{ $alerte->user->telephone }}">Appeler {{ $alerte->user->nomAffiche() }}</a>
        @if($alerte->dernierePosition)
            <a class="btn gris" id="itineraire" href="https://www.google.com/maps/dir/?api=1&destination={{ $alerte->dernierePosition->lat }},{{ $alerte->dernierePosition->lng }}">Itinéraire</a>
        @endif
        <a class="btn rouge" href="tel:185">Appeler le SAMU (185)</a>
        <a class="btn rouge" href="tel:180">Appeler les pompiers (180)</a>
        <p class="muted">BRAVIDO ne remplace pas les services de secours. Cette page se met à jour toutes les 30 secondes.</p>
    @else
        <div class="carte">
            <h1 style="margin:0 0 8px;font-size:22px">Alerte terminée</h1>
            <p>Cette alerte a été clôturée ou a expiré. La position n'est plus partagée.</p>
        </div>
    @endif
@endsection

@if($active)
    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
        <script>
            (function () {
                const depart = @json($alerte->dernierePosition ? [$alerte->dernierePosition->lat, $alerte->dernierePosition->lng] : [5.36, -4.0083]);
                const carte = L.map('carte').setView(depart, 16);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(carte);
                const marqueur = L.marker(depart).addTo(carte);
                const url = @json(route('alerte.position', request()->route('token')));

                setInterval(async () => {
                    try {
                        const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const d = await r.json();
                        if (!d.active) { location.reload(); return; }
                        if (d.lat) {
                            marqueur.setLatLng([d.lat, d.lng]);
                            carte.panTo([d.lat, d.lng]);
                            document.getElementById('maj').textContent = 'Position du ' + new Date(d.recorded_at).toLocaleTimeString('fr-FR')
                                + (d.precision_m ? ' (précision ± ' + d.precision_m + ' m)' : '');
                            const it = document.getElementById('itineraire');
                            if (it) it.href = 'https://www.google.com/maps/dir/?api=1&destination=' + d.lat + ',' + d.lng;
                        }
                    } catch (e) { /* réseau instable : nouvel essai au prochain tour */ }
                }, 30000);
            })();
        </script>
    @endpush
@endif
