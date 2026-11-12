@extends('admin.layout')
@section('titre', 'Importer un programme de garde')

@section('contenu')
    <h1>Importer un programme de garde</h1>

    <div class="carte">
        <p>Fichier CSV (séparateur « ; » ou « , ») ou Excel. Colonnes reconnues :</p>
        <ul>
            <li><strong>nom</strong> de la pharmacie (ou <strong>id</strong> BRAVIDO, plus fiable)</li>
            <li><strong>commune</strong> : utile si deux pharmacies portent le même nom</li>
            <li><strong>date_debut</strong>, <strong>date_fin</strong> (facultatives, JJ/MM/AAAA) : à défaut, la période ci-dessous s'applique</li>
        </ul>
        <p>Rien n'est publié à cette étape : un aperçu affiche les lignes reconnues, les erreurs et les doublons.
            <a href="{{ route('admin.gardes.modele') }}">Télécharger le modèle CSV</a></p>
    </div>

    <form method="post" action="{{ route('admin.gardes.store') }}" enctype="multipart/form-data" class="carte">
        @csrf
        <div class="ligne">
            <div>
                <label for="ville_id">Ville *</label>
                <select id="ville_id" name="ville_id" required>
                    @foreach($villes as $v)
                        <option value="{{ $v->id }}" @selected(old('ville_id') == $v->id)>{{ $v->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date_debut">Début de la période *</label>
                <input type="date" id="date_debut" name="date_debut" value="{{ old('date_debut', $debut) }}" required>
            </div>
            <div>
                <label for="date_fin">Fin de la période *</label>
                <input type="date" id="date_fin" name="date_fin" value="{{ old('date_fin', $fin) }}" required>
            </div>
        </div>
        <label for="fichier">Fichier *</label>
        <input type="file" id="fichier" name="fichier" accept=".csv,.txt,.xlsx,.xls" required>
        <div class="actions"><button class="btn">Analyser le fichier</button></div>
    </form>
@endsection
