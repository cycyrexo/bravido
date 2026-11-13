@extends('admin.layout')
@section('titre', 'Avis')

@section('contenu')
    <h1>Avis et notes</h1>
    <p class="sous-titre">Pré-modération : un avis ou une réponse n'est visible dans l'application qu'après validation. Les passages surlignés sont signalés automatiquement (jamais validés automatiquement).</p>

    <div class="actions" style="margin:0 0 16px">
        @foreach(['en_attente' => 'Avis en attente', 'reponses' => 'Réponses en attente', 'approuve' => 'Publiés', 'rejete' => 'Rejetés'] as $cle => $libelle)
            <a class="btn petit {{ $onglet === $cle ? '' : 'secondaire' }}" href="{{ route('admin.avis.index', ['onglet' => $cle]) }}">
                {{ $libelle }}@if($cle === 'en_attente' && $compteurs['en_attente']) <span class="compteur">{{ $compteurs['en_attente'] }}</span>@endif
                @if($cle === 'reponses' && $compteurs['reponses']) <span class="compteur">{{ $compteurs['reponses'] }}</span>@endif
            </a>
        @endforeach
    </div>

    @forelse($avis as $a)
        @php($enReponse = $onglet === 'reponses')
        @php($drapeaux = $enReponse ? ($a->reponse_drapeaux ?? []) : ($a->drapeaux ?? []))
        <div class="carte" style="{{ $drapeaux ? 'border-left:4px solid var(--orange)' : '' }}">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div>
                    <strong>{{ $a->etablissement->nom }}</strong>
                    <span class="muted">· {{ $a->etablissement->commune?->nom }}</span><br>
                    <span title="{{ $a->note }} / 5" style="color:#d98a00;letter-spacing:2px">{{ str_repeat('★', $a->note) }}<span style="color:#c9d2d5">{{ str_repeat('★', 5 - $a->note) }}</span></span>
                    <span class="muted">{{ $a->note }}/5 · {{ $a->auteur->nomAffiche() }} · {{ $a->created_at->format('d/m/Y H:i') }}</span>
                </div>
                <div>
                    @foreach($drapeaux as $d)
                        <span class="pastille {{ in_array($d, ['accusation', 'insulte', 'telephone']) ? 'rouge' : 'orange' }}">{{ \App\Services\AvisModeration::LIBELLES[$d] ?? $d }}</span>
                    @endforeach
                    @if($a->signalements->isNotEmpty())
                        <span class="pastille rouge">{{ $a->signalements->count() }} signalement(s)</span>
                    @endif
                </div>
            </div>

            <p style="margin:10px 0">{!! $a->commentaire ? $moderation->surligner($a->commentaire) : '<span class="muted">(note seule, sans commentaire)</span>' !!}</p>
            @foreach($a->signalements->whereNotNull('motif') as $s)
                <p class="muted" style="margin:0 0 6px">Signalement : « {{ $s->motif }} »</p>
            @endforeach

            @if($a->reponse)
                <div style="background:#f3f6f7;border-radius:10px;padding:10px;margin:8px 0">
                    <strong>Réponse du responsable</strong>
                    <span class="pastille {{ ['approuvee' => 'vert', 'rejetee' => 'rouge'][$a->reponse_statut] ?? 'orange' }}">{{ ['approuvee' => 'publiée', 'rejetee' => 'rejetée', 'en_attente' => 'en attente'][$a->reponse_statut] ?? '' }}</span><br>
                    {!! $moderation->surligner($a->reponse) !!}
                </div>
            @endif
            @if($a->statut === 'rejete' && $a->motif_rejet)
                <p class="muted">Motif du rejet : {{ $a->motif_rejet }}</p>
            @endif

            <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
                @if($enReponse)
                    <form method="post" action="{{ route('admin.avis.reponse.valider', $a) }}">@csrf <button class="btn petit">Valider la réponse</button></form>
                    <form method="post" action="{{ route('admin.avis.reponse.rejeter', $a) }}" style="display:flex;gap:6px">@csrf
                        <input name="motif_rejet" placeholder="Motif du rejet" required maxlength="500" style="width:260px"><button class="btn petit secondaire">Rejeter la réponse</button></form>
                @else
                    @if($a->statut !== 'approuve')
                        <form method="post" action="{{ route('admin.avis.valider', $a) }}">@csrf <button class="btn petit">Valider</button></form>
                    @endif
                    @if($a->statut !== 'rejete')
                        <form method="post" action="{{ route('admin.avis.rejeter', $a) }}" style="display:flex;gap:6px">@csrf
                            <input name="motif_rejet" placeholder="Motif du rejet (envoyé à l'auteur)" required maxlength="500" style="width:300px"><button class="btn petit secondaire">Rejeter</button></form>
                    @endif
                @endif
            </div>
        </div>
    @empty
        <p class="muted">Rien à modérer ici.</p>
    @endforelse

    {{ $avis->links('pagination::simple-default') }}
@endsection
