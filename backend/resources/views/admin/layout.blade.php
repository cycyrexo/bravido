<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('titre', 'Back-office') · BRAVIDO</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @stack('head')
</head>
<body>
<div class="layout">
    <nav class="sidebar">
        <div class="logo"><img src="{{ asset('img/logo-bravido.png') }}" alt="BRAVIDO"></div>
        <div class="service">Service BRAVIDO</div>
        @php
            $r = request()->route()?->getName() ?? '';
            $compteurs = [
                'fiches' => \App\Models\Fiche::where('statut', 'en_attente')->count(),
                'messages' => \App\Models\MessageSupport::where('statut', '!=', 'traite')->count(),
                'alertes' => \App\Models\Alerte::where('statut', 'active')->where('expires_at', '>', now())->count(),
            ];
            $lien = fn (string $route, string $prefixe, string $libelle, ?int $compteur = null) =>
                '<a href="'.e(route($route)).'" class="'.(str_starts_with($r, $prefixe) ? 'actif' : '').'"><span>'.e($libelle).'</span>'
                .($compteur ? '<span class="compteur">'.$compteur.'</span>' : '').'</a>';
        @endphp
        {!! $lien('admin.dashboard', 'admin.dashboard', 'Tableau de bord') !!}
        {!! $lien('admin.gardes.index', 'admin.gardes', 'Gardes') !!}
        {!! $lien('admin.etablissements.index', 'admin.etablissements', 'Établissements') !!}
        {!! $lien('admin.fiches.index', 'admin.fiches', 'Fiches', $compteurs['fiches']) !!}
        {!! $lien('admin.messages.index', 'admin.messages', 'Messages', $compteurs['messages']) !!}
        @if(auth()->user()->isAdmin())
            {!! $lien('admin.publicites.index', 'admin.publicites', 'Publicité') !!}
        @endif
        {!! $lien('admin.alertes.index', 'admin.alertes', 'Alertes', $compteurs['alertes']) !!}
        @if(auth()->user()->isAdmin())
            <div class="separateur"></div>
            {!! $lien('admin.utilisateurs.index', 'admin.utilisateurs', 'Utilisateurs') !!}
            {!! $lien('admin.journal.index', 'admin.journal', 'Journal des actions') !!}
        @endif
        <form method="post" action="{{ route('admin.logout') }}">
            @csrf
            <div class="muted">{{ auth()->user()->nomAffiche() }} · {{ auth()->user()->role }}</div>
            <button class="btn secondaire petit" style="margin-top:8px">Se déconnecter</button>
        </form>
    </nav>
    <main class="contenu">
        @if(session('succes'))
            <div class="flash succes">{{ session('succes') }}</div>
        @endif
        @if($errors->any())
            <div class="flash erreurs"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif
        @yield('contenu')
    </main>
</div>
@stack('scripts')
</body>
</html>
