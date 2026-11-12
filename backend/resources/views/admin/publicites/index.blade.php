@extends('admin.layout')
@section('titre', 'Régie publicitaire')

@section('contenu')
    <h1>Régie publicitaire <a class="btn petit" href="{{ route('admin.publicites.create') }}">+ Nouvelle bannière</a></h1>
    <p class="muted">Les bannières s'affichent uniquement sur l'écran d'accueil, jamais dans l'écran Alerte. Les annonces pour des produits de santé doivent respecter la charte annonceurs.</p>

    <table>
        <thead><tr><th>Bannière</th><th>Annonceur</th><th>Diffusion</th><th>Ciblage</th><th>Impressions</th><th>Clics</th><th>Taux de clic</th><th></th></tr></thead>
        <tbody>
        @forelse($publicites as $p)
            @php($enCours = $p->actif && $p->date_debut->lte(today()) && $p->date_fin->gte(today()))
            <tr>
                <td><img src="{{ $p->imageUrl() }}" alt="" style="width:160px;border-radius:6px"></td>
                <td>{{ $p->annonceur }}<br><span class="muted">{{ $p->titre }}</span></td>
                <td>
                    {{ $p->date_debut->format('d/m/Y') }} → {{ $p->date_fin->format('d/m/Y') }}<br>
                    <span class="pastille {{ $enCours ? 'vert' : '' }}">{{ $enCours ? 'en diffusion' : ($p->actif ? 'programmée / terminée' : 'inactive') }}</span>
                </td>
                <td>{{ $p->villes->isEmpty() ? 'National' : $p->villes->pluck('nom')->implode(', ') }}</td>
                <td>{{ number_format($p->impressions_total, 0, ',', ' ') }}</td>
                <td>{{ number_format($p->clics_total, 0, ',', ' ') }}</td>
                <td>{{ $p->impressions_total > 0 ? number_format(100 * $p->clics_total / $p->impressions_total, 2, ',', ' ').' %' : '—' }}</td>
                <td>
                    <a class="btn petit secondaire" href="{{ route('admin.publicites.edit', $p) }}">Modifier</a>
                    <form class="inline" method="post" action="{{ route('admin.publicites.destroy', $p) }}" onsubmit="return confirm('Supprimer cette bannière ?')">
                        @csrf @method('DELETE')<button class="btn petit danger">Supprimer</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Aucune bannière.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $publicites->links('pagination::simple-default') }}
@endsection
