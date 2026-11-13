@extends('admin.layout')
@section('titre', 'Alerte #'.$alerte->id)

@php
    $fmt = fn (?int $s) => $s === null ? '—' : ($s < 60 ? $s.' s' : intdiv($s, 60).' min '.($s % 60).' s');
@endphp

@section('contenu')
    <p><a href="{{ route('admin.alertes.index') }}">← Journal des alertes</a></p>
    <h1>Alerte #{{ $alerte->id }} · {{ $alerte->libelleMotif() }}</h1>
    <p class="sous-titre">
        Déclenchée le {{ $alerte->started_at->format('d/m/Y à H:i:s') }} ·
        @if($alerte->statut === 'active' && $alerte->expires_at->isFuture()) <span class="pastille rouge">Active</span>
        @elseif($alerte->statut === 'annulee') <span class="pastille">Annulée</span>
        @else <span class="pastille vert">Clôturée</span> @endif
        · Journal d'audit sans position, sans message ni donnée médicale ni identité.
    </p>

    <div class="grille" style="max-width:900px">
        <div class="kpi"><div class="valeur">{{ $fmt($stats['premier_push_s']) }}</div><div class="libelle">Délai jusqu'au 1<sup>er</sup> push</div></div>
        <div class="kpi"><div class="valeur">{{ $fmt($stats['premiere_vue_s']) }}</div><div class="libelle">Délai jusqu'à la 1<sup>re</sup> ouverture</div></div>
        <div class="kpi"><div class="valeur">{{ $fmt($stats['premiere_reponse_s']) }}</div><div class="libelle">Délai jusqu'à la 1<sup>re</sup> réponse</div></div>
        <div class="kpi"><div class="valeur {{ $stats['sms_utilise'] ? 'orange' : '' }}">{{ $stats['sms_utilise'] ? 'Oui ('.$stats['sms_nombre'].')' : 'Non' }}</div><div class="libelle">SMS de secours utilisé</div></div>
        <div class="kpi"><div class="valeur">{{ $alerte->vues_count }}/{{ $alerte->destinataires_count }}</div><div class="libelle">Complices ayant ouvert · {{ $alerte->recues_count }} reçues · {{ $alerte->reponses_count }} réponses</div></div>
    </div>

    <h2>Chronologie</h2>
    <table style="max-width:900px">
        <thead><tr><th>Heure</th><th>+ depuis le départ</th><th>Événement</th><th>Complice (rang)</th><th>Canal</th></tr></thead>
        <tbody>
        @forelse($evenements as $e)
            <tr>
                <td>{{ $e->created_at->format('H:i:s') }}</td>
                <td class="muted">+{{ $fmt(max(0, (int) $alerte->started_at->diffInSeconds($e->created_at, false))) }}</td>
                <td>{{ \App\Models\AlerteEvenement::TYPES[$e->type] ?? $e->type }}</td>
                <td>{{ $e->destinataire_ref ? '#'.$e->destinataire_ref : '—' }}</td>
                <td>{{ $e->canal ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Aucun événement enregistré (alerte antérieure à la mise en place du journal d'audit).</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
