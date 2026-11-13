@extends('admin.layout')
@section('titre', 'Journal des alertes')

@section('contenu')
    <h1>Journal des alertes</h1>
    <p class="sous-titre">Suivi technique : remise des notifications aux complices. Le contenu reste privé entre l'utilisateur et ses complices.</p>

    <div class="actions" style="margin:0 0 16px">
        @foreach(['' => 'Toutes', 'active' => 'Actives', 'close' => 'Clôturées', 'annulee' => 'Annulées'] as $cle => $libelle)
            <a class="btn petit {{ ($statut ?? '') === $cle ? '' : 'secondaire' }}" href="{{ route('admin.alertes.index', $cle ? ['statut' => $cle] : []) }}">{{ $libelle }}</a>
        @endforeach
    </div>

    @if(auth()->user()->isAdmin())
        <form method="get" action="{{ route('admin.alertes.export') }}" class="actions" style="margin:0 0 16px;display:flex;gap:8px;align-items:flex-end">
            <div><label style="margin:0">Du</label><input type="date" name="du"></div>
            <div><label style="margin:0">Au</label><input type="date" name="au"></div>
            <button class="btn petit secondaire">Exporter le journal d'audit (CSV)</button>
        </form>
    @endif

    <table>
        <thead><tr><th>Heure</th><th>Motif</th><th>Complices</th><th>Remise</th><th>SMS de secours</th><th>Réponses</th><th>Statut</th></tr></thead>
        <tbody>
        @forelse($alertes as $a)
            @php($active = $a->statut === 'active' && $a->expires_at->isFuture())
            <tr>
                <td><a href="{{ route('admin.alertes.show', $a) }}">{{ $a->started_at->format('d/m H:i') }}</a></td>
                <td>{{ $a->libelleMotif() }}</td>
                <td>{{ $a->destinataires_count }}</td>
                <td>{{ $a->vues_count }}/{{ $a->destinataires_count }} vues <span class="muted">· {{ $a->recues_count }} reçues</span></td>
                <td>{{ $a->sms_count }}</td>
                <td>{{ $a->reponses_count }}</td>
                <td>
                    @if($active)
                        <span class="pastille rouge">Active</span>
                    @elseif($a->statut === 'annulee')
                        <span class="pastille">Annulée</span>
                    @else
                        <span class="pastille vert">Clôturée</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Aucune alerte.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $alertes->links('pagination::simple-default') }}
@endsection
