<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('titre') · BRAVIDO</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <style>
        body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1d2327; background: #f5f7f8; }
        .page { max-width: 560px; margin: 0 auto; padding: 20px 16px; }
        .logo { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 10px; } .logo img { height: 32px; width: auto; display: block; }
        .carte { background: #fff; border-radius: 12px; padding: 18px; margin: 16px 0; border: 1px solid #dfe4e7; }
        .alerte { border-left: 6px solid #c62828; }
        .btn { display: block; text-align: center; padding: 14px; border-radius: 10px; font-weight: 700; text-decoration: none; margin: 10px 0; border: 0; width: 100%; font-size: 16px; cursor: pointer; }
        .rouge { background: #c62828; color: #fff; } .vert { background: #0f8a6c; color: #fff; } .gris { background: #e7ecee; color: #1d2327; }
        .muted { color: #5f6b73; font-size: 14px; }
        #carte { height: 320px; border-radius: 12px; }
        .erreur { background: #fbe0de; color: #c62828; padding: 10px; border-radius: 8px; }
    </style>
    @stack('head')
</head>
<body>
<div class="page">
    <div class="logo"><img src="{{ asset('img/logo-bravido.png') }}" alt="BRAVIDO"> <span class="muted">· Partout et tout le temps</span></div>
    @yield('contenu')
</div>
@stack('scripts')
</body>
</html>
