@extends('admin.layout')
@section('titre', 'Premium · '.$e->nom)

@section('contenu')
    <h1>{{ $e->nom }}
        @if($e->estPremium())<span class="pastille vert">Premium actif</span>@elseif($e->premium)<span class="pastille rouge">Premium expiré</span>@else<span class="pastille">Non Premium</span>@endif
    </h1>
    <p class="muted">{{ $e->categorie->libelle ?? '' }} · {{ $e->commune->nom }} ({{ $e->commune->ville->nom }}) ·
        <a href="{{ route('admin.etablissements.edit', $e) }}">Fiche complète</a> · <a href="{{ route('admin.premium.index') }}">Retour à la liste</a></p>

    <h2>Statistiques · {{ $stats['jours'] }} derniers jours</h2>
    <div class="ligne">
        <div class="carte"><strong>{{ $stats['vues'] }}</strong><div class="muted">Vues de la fiche</div></div>
        <div class="carte"><strong>{{ $stats['appels'] }}</strong><div class="muted">Appels</div></div>
        <div class="carte"><strong>{{ $stats['itineraires'] }}</strong><div class="muted">Itinéraires</div></div>
    </div>

    <form method="post" action="{{ route('admin.premium.update', $e) }}" class="carte">
        @csrf @method('PUT')
        <label><input type="checkbox" name="premium" value="1" @checked(old('premium', $e->premium))> Fiche Premium</label>
        <label for="premium_jusqu_au">Valable jusqu'au (vide = sans date de fin)</label>
        <input type="date" id="premium_jusqu_au" name="premium_jusqu_au" value="{{ old('premium_jusqu_au', $e->premium_jusqu_au?->toDateString()) }}">

        <label for="description_longue">Description détaillée (pas d'allégation de santé : charte publicitaire)</label>
        <textarea id="description_longue" name="description_longue" rows="5" maxlength="4000">{{ old('description_longue', $e->description_longue) }}</textarea>

        <label>Horaires détaillés (ex. 08:00-20:00, Fermé)</label>
        @foreach($jours as $j)
            <div class="ligne"><div style="max-width:110px">{{ ['lun' => 'Lundi', 'mar' => 'Mardi', 'mer' => 'Mercredi', 'jeu' => 'Jeudi', 'ven' => 'Vendredi', 'sam' => 'Samedi', 'dim' => 'Dimanche'][$j] }}</div>
                <div><input type="text" name="jours[{{ $j }}]" maxlength="60" value="{{ old('jours.'.$j, $e->horaires_detail[$j] ?? '') }}"></div></div>
        @endforeach
        <div class="actions"><button class="btn">Enregistrer</button></div>
    </form>

    <h2>Photos ({{ $e->photos->count() }}/{{ $photosMax }})</h2>
    <div class="carte">
        <div style="display:flex;gap:12px;flex-wrap:wrap">
            @foreach($e->photos as $p)
                <div>
                    <img src="{{ $p->urlMiniature() }}" alt="" style="width:140px;height:140px;object-fit:cover;border-radius:8px;display:block">
                    <form method="post" action="{{ route('admin.premium.photos.supprimer', [$e, $p]) }}" onsubmit="return confirm('Supprimer cette photo ?')">@csrf @method('DELETE')
                        <button class="btn petit danger" style="margin-top:6px">Supprimer</button></form>
                </div>
            @endforeach
            @if($e->photos->isEmpty())<span class="muted">Aucune photo.</span>@endif
        </div>
        @if($e->photos->count() < $photosMax)
            <form method="post" action="{{ route('admin.premium.photos.ajouter', $e) }}" enctype="multipart/form-data" style="margin-top:14px">
                @csrf
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
                <button class="btn secondaire petit">Ajouter la photo</button>
                <span class="muted">JPEG, PNG ou WebP, 8 Mo maximum : redimensionnée et miniaturisée automatiquement.</span>
            </form>
        @endif
    </div>
@endsection
