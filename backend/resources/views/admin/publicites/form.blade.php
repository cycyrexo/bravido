@extends('admin.layout')
@php($p = $publicite)
@section('titre', $p->exists ? 'Modifier la bannière' : 'Nouvelle bannière')

@section('contenu')
    <h1>{{ $p->exists ? 'Modifier la bannière' : 'Nouvelle bannière' }}</h1>

    <form method="post" enctype="multipart/form-data" class="carte"
          action="{{ $p->exists ? route('admin.publicites.update', $p) : route('admin.publicites.store') }}">
        @csrf
        @if($p->exists) @method('PUT') @endif

        <div class="ligne">
            <div><label for="annonceur">Annonceur *</label><input type="text" id="annonceur" name="annonceur" value="{{ old('annonceur', $p->annonceur) }}" required></div>
            <div><label for="titre">Titre (accessibilité)</label><input type="text" id="titre" name="titre" value="{{ old('titre', $p->titre) }}"></div>
        </div>
        <label for="lien">Lien de l'annonceur</label>
        <input type="url" id="lien" name="lien" value="{{ old('lien', $p->lien) }}" placeholder="https://">

        <div class="ligne">
            <div><label for="date_debut">Début *</label><input type="date" id="date_debut" name="date_debut" value="{{ old('date_debut', $p->date_debut?->toDateString()) }}" required></div>
            <div><label for="date_fin">Fin *</label><input type="date" id="date_fin" name="date_fin" value="{{ old('date_fin', $p->date_fin?->toDateString()) }}" required></div>
            <div><label for="ordre">Ordre d'affichage</label><input type="number" id="ordre" name="ordre" min="0" value="{{ old('ordre', $p->ordre) }}"></div>
        </div>

        <label for="image">Image {{ $p->exists ? '(laisser vide pour conserver l\'actuelle)' : '*' }} — 1200 × 400 px conseillé, 2 Mo max</label>
        @if($p->exists)<img src="{{ $p->imageUrl() }}" alt="" style="max-width:360px;border-radius:6px;display:block;margin-bottom:8px">@endif
        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" @required(! $p->exists)>

        <label>Ciblage par ville (aucune case = diffusion nationale)</label>
        @php($villesActuelles = old('villes', $p->relationLoaded('villes') ? $p->villes->pluck('id')->all() : []))
        <div class="cases">
            @foreach($villes as $v)
                <label><input type="checkbox" name="villes[]" value="{{ $v->id }}" @checked(in_array($v->id, array_map('intval', $villesActuelles), true))> {{ $v->nom }}</label>
            @endforeach
        </div>

        <div class="cases" style="margin-top:12px">
            <label><input type="checkbox" name="actif" value="1" @checked(old('actif', $p->actif))> Active</label>
        </div>

        <div class="actions">
            <button class="btn">Enregistrer</button>
            <a class="btn secondaire" href="{{ route('admin.publicites.index') }}">Retour</a>
        </div>
    </form>
@endsection
