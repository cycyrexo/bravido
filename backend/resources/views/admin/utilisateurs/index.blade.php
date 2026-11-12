@extends('admin.layout')
@section('titre', 'Utilisateurs')

@section('contenu')
    <h1>Utilisateurs</h1>

    <form class="filtres" method="get">
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}" placeholder="Téléphone, nom ou e-mail">
        <select name="role">
            <option value="">Tous rôles</option>
            @foreach(\App\Models\User::ROLES as $role)
                <option value="{{ $role }}" @selected(($f['role'] ?? '') === $role)>{{ $role }}</option>
            @endforeach
        </select>
        <button class="btn secondaire">Filtrer</button>
    </form>

    <table>
        <thead><tr><th>Téléphone</th><th>Nom</th><th>Ville</th><th>Inscrit le</th><th>Rôle, statut, accès back-office</th></tr></thead>
        <tbody>
        @forelse($utilisateurs as $u)
            <tr>
                <td>{{ $u->telephone }}</td>
                <td>{{ $u->nom ?? '—' }}</td>
                <td>{{ $u->ville?->nom ?? '—' }}</td>
                <td>{{ $u->created_at->format('d/m/Y') }}</td>
                <td>
                    <form method="post" action="{{ route('admin.utilisateurs.update', $u) }}" class="filtres" style="margin:0">
                        @csrf @method('PUT')
                        <select name="role">
                            @foreach(\App\Models\User::ROLES as $role)
                                <option value="{{ $role }}" @selected($u->role === $role)>{{ $role }}</option>
                            @endforeach
                        </select>
                        <select name="statut">
                            <option value="actif" @selected($u->statut === 'actif')>actif</option>
                            <option value="suspendu" @selected($u->statut === 'suspendu')>suspendu</option>
                        </select>
                        <input type="email" name="email" value="{{ $u->email }}" placeholder="e-mail (staff)" style="width:180px">
                        <input type="password" name="password" placeholder="nouveau mot de passe" style="width:170px" autocomplete="new-password">
                        <button class="btn petit">OK</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Aucun utilisateur.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $utilisateurs->links('pagination::simple-default') }}
@endsection
