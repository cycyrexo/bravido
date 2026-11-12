@extends('admin.layout')
@section('titre', 'Messagerie support')

@section('contenu')
    <h1>Messagerie support</h1>

    <form class="filtres" method="get">
        <select name="statut">
            <option value="">Non traités</option>
            @foreach(['nouveau' => 'Nouveaux', 'en_cours' => 'En cours', 'traite' => 'Traités'] as $cle => $libelle)
                <option value="{{ $cle }}" @selected(($f['statut'] ?? '') === $cle)>{{ $libelle }}</option>
            @endforeach
        </select>
        <select name="type">
            <option value="">Tous types</option>
            @foreach(\App\Models\MessageSupport::TYPES as $type)
                <option value="{{ $type }}" @selected(($f['type'] ?? '') === $type)>{{ $type }}</option>
            @endforeach
        </select>
        <button class="btn secondaire">Filtrer</button>
    </form>

    <table>
        <thead><tr><th>Type</th><th>Message</th><th>Utilisateur</th><th>Date</th><th>Statut</th></tr></thead>
        <tbody>
        @forelse($messages as $m)
            <tr>
                <td><span class="pastille {{ $m->type === 'signalement' ? 'orange' : '' }}">{{ $m->type }}</span></td>
                <td>
                    <a href="{{ route('admin.messages.show', $m) }}">{{ \Illuminate\Support\Str::limit($m->texte, 100) }}</a>
                    @if($m->etablissement)<br><span class="muted">Concerne : {{ $m->etablissement->nom }}</span>@endif
                </td>
                <td>{{ $m->user->nomAffiche() }}</td>
                <td>{{ $m->created_at->format('d/m/Y H:i') }}</td>
                <td><span class="pastille {{ $m->statut === 'traite' ? 'vert' : ($m->statut === 'nouveau' ? 'rouge' : 'orange') }}">{{ $m->statut }}</span></td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Aucun message.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $messages->links('pagination::simple-default') }}
@endsection
