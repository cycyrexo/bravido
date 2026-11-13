@extends('public.layout')
@section('titre', "Télécharger l'application")

@push('head')
<style>
    .qr { display: flex; justify-content: center; margin: 12px 0; }
    .qr svg { width: 200px; height: 200px; max-width: 100%; background: #fff; }
    .badge { display: inline-block; background: #fff3cd; color: #7a5a00; border-radius: 999px; padding: 2px 10px; font-size: 13px; font-weight: 700; }
    .btn-gros { font-size: 18px; padding: 18px; }
    ol.etapes { padding-left: 20px; margin: 8px 0 0; } ol.etapes li { margin: 8px 0; }
    .hash { word-break: break-all; font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
    dl.infos { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin: 0; font-size: 14px; }
    dl.infos dt { color: #5f6b73; } dl.infos dd { margin: 0; }
</style>
@endpush

@section('contenu')
    <div class="carte">
        <h1 style="margin:0 0 8px;font-size:22px">Application BRAVIDO pour Android</h1>
        <span class="badge">Version de test</span>
        <p class="muted">Cette version est destinée aux tests : elle peut contenir des défauts et évoluer sans préavis.</p>

        @if ($disponible)
            <a class="btn vert btn-gros" href="{{ $urlApk }}" download="bravido-test.apk">Télécharger l'application (Android)</a>

            <dl class="infos">
                @if (!empty($meta['version']))<dt>Version</dt><dd>{{ $meta['version'] }}</dd>@endif
                @if (!empty($meta['build_date_fr']))<dt>Date</dt><dd>{{ $meta['build_date_fr'] }}</dd>@endif
                <dt>Taille</dt><dd>{{ $meta['taille'] }}</dd>
                @if (!empty($meta['sha256']))<dt>SHA-256</dt><dd class="hash">{{ $meta['sha256'] }}</dd>@endif
            </dl>
        @else
            <div class="erreur" role="status">L'APK n'est pas encore disponible. Merci de réessayer un peu plus tard.</div>
        @endif
    </div>

    <div class="carte">
        <h2 style="margin:0;font-size:18px">Sur un autre téléphone</h2>
        <p class="muted" style="margin:4px 0 0">Scannez ce code avec l'appareil photo du téléphone Android pour ouvrir cette page.</p>
        <div class="qr">{!! $qr !!}</div>
    </div>

    <div class="carte">
        <h2 style="margin:0;font-size:18px">Installer en 7 étapes</h2>
        <ol class="etapes">
            <li>Ouvrez cette page sur votre téléphone Android (ou scannez le QR code ci-dessus).</li>
            <li>Touchez « Télécharger l'application (Android) » et attendez la fin du téléchargement.</li>
            <li>Si Android affiche « Autoriser les sources inconnues » (ou « Installer des applications inconnues »), activez l'autorisation pour votre navigateur ou pour l'application « Fichiers » utilisée.</li>
            <li>Ouvrez le fichier <strong>bravido-test.apk</strong> téléchargé (notification, ou dossier « Téléchargements »).</li>
            <li>Touchez « Installer ». Si Google Play Protect propose une analyse, vous pouvez continuer l'installation.</li>
            <li>Ouvrez BRAVIDO et acceptez l'accès à la localisation et aux notifications.</li>
            <li>Connectez-vous avec votre numéro de téléphone.</li>
        </ol>
    </div>

    <div class="carte">
        <p class="muted" style="margin:0"><strong>Version iPhone :</strong> bientôt.</p>
    </div>
@endsection
