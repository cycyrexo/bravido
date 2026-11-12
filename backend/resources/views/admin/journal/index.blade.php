@extends('admin.layout')
@section('titre', 'Journal')

@section('contenu')
    <h1>Journal des actions</h1>

    <table>
        <thead><tr><th>Date</th><th>Auteur</th><th>Action</th><th>Cible</th><th>Détail</th><th>IP</th></tr></thead>
        <tbody>
        @forelse($entrees as $e)
            <tr>
                <td>{{ $e->created_at?->format('d/m/Y H:i:s') }}</td>
                <td>{{ $e->admin?->nomAffiche() ?? '—' }}</td>
                <td>{{ $e->action }}</td>
                <td>{{ $e->cible_type }} @if($e->cible_id)#{{ $e->cible_id }}@endif</td>
                <td>
                    @if($e->avant || $e->apres)
                        <details><summary class="muted">voir</summary>
                            @if($e->avant)<pre style="white-space:pre-wrap;font-size:12px">Avant : {{ json_encode($e->avant, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                            @if($e->apres)<pre style="white-space:pre-wrap;font-size:12px">Après : {{ json_encode($e->apres, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                        </details>
                    @endif
                </td>
                <td class="muted">{{ $e->ip }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Journal vide.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $entrees->links('pagination::simple-default') }}
@endsection
