<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Paiement simulé · BRAVIDO</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f4f7f8; color: #16242a; }
        main { max-width: 460px; margin: 0 auto; padding: 24px 16px; }
        .carte { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 6px 18px rgba(11,42,51,.08); }
        .bandeau { background: #fff1d6; color: #6b3d00; border-radius: 10px; padding: 8px 12px; font-size: 13px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .montant { font-size: 30px; font-weight: 800; margin: 12px 0; }
        button { width: 100%; border: 0; border-radius: 12px; padding: 14px; font-size: 16px; font-weight: 700; margin-top: 10px; cursor: pointer; }
        .ok { background: #167a74; color: #fff; } .ko { background: #fff; border: 1px solid #c9d2d5; color: #16242a; }
        .muted { color: #5a6870; font-size: 13px; }
    </style>
</head>
<body>
<main>
    <div class="bandeau">Environnement de test : aucun argent réel n'est prélevé.</div>
    <div class="carte">
        <h1>{{ $paiement->plan->nom }}</h1>
        <div class="muted">Référence {{ $paiement->reference }}</div>
        <div class="montant">{{ number_format($paiement->montant, 0, ',', ' ') }} {{ $paiement->devise }}</div>
        @if($termine)
            @if($paiement->statut === 'reussi')
                <p><strong>Paiement simulé réussi.</strong> Votre abonnement est actif : vous pouvez revenir dans l'application BRAVIDO.</p>
            @else
                <p><strong>Paiement simulé échoué.</strong> Aucun abonnement n'a été activé. Vous pouvez revenir dans l'application et réessayer.</p>
            @endif
        @else
            <form method="post" action="{{ route('paiement.sandbox.simuler', [$paiement->reference, 'succes']) }}">@csrf<button class="ok">Simuler un succès</button></form>
            <form method="post" action="{{ route('paiement.sandbox.simuler', [$paiement->reference, 'echec']) }}">@csrf<button class="ko">Simuler un échec</button></form>
        @endif
        <p class="muted" style="margin-top:16px">Les alertes d'urgence BRAVIDO restent gratuites et identiques pour tous, abonnés ou non.</p>
    </div>
</main>
</body>
</html>
