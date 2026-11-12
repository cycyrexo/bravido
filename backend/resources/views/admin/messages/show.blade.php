@extends('admin.layout')
@section('titre', 'Message #'.$message->id)

@section('contenu')
    <h1>Message #{{ $message->id }} <span class="pastille">{{ $message->type }}</span></h1>

    <div class="carte">
        <p class="muted">{{ $message->user->nomAffiche() }} · {{ $message->user->telephone }} · {{ $message->created_at->format('d/m/Y à H:i') }}</p>
        @if($message->etablissement)
            <p>Établissement concerné :
                <a href="{{ route('admin.etablissements.edit', $message->etablissement) }}">{{ $message->etablissement->nom }}</a></p>
        @endif
        <p style="white-space:pre-line">{{ $message->texte }}</p>
    </div>

    @if($message->reponse)
        <div class="carte">
            <p class="muted">Réponse de {{ $message->reponduPar?->nomAffiche() }} le {{ $message->repondu_at?->format('d/m/Y à H:i') }}</p>
            <p style="white-space:pre-line">{{ $message->reponse }}</p>
        </div>
    @endif

    <form method="post" action="{{ route('admin.messages.repondre', $message) }}" class="carte">
        @csrf
        <label for="reponse">{{ $message->reponse ? 'Nouvelle réponse' : 'Réponse' }} (visible dans l'application)</label>
        <textarea id="reponse" name="reponse" required>{{ old('reponse') }}</textarea>
        <label for="statut">Statut après envoi</label>
        <select id="statut" name="statut" style="max-width:220px">
            <option value="traite">Traité</option>
            <option value="en_cours">En cours</option>
        </select>
        <div class="actions">
            <button class="btn">Envoyer</button>
            <a class="btn secondaire" href="{{ route('admin.messages.index') }}">Retour</a>
        </div>
    </form>
@endsection
