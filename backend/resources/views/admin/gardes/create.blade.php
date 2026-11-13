@extends('admin.layout')
@section('titre', 'Importer un programme de garde')

@section('contenu')
    <h1>Importer un programme de garde</h1>

    <div class="carte">
        <p><strong>Tableau</strong> (CSV séparé par « ; » ou « , », Excel) : colonnes reconnues</p>
        <ul>
            <li><strong>nom</strong> de la pharmacie (ou <strong>id</strong> BRAVIDO, plus fiable)</li>
            <li><strong>commune</strong> : utile si deux pharmacies portent le même nom</li>
            <li><strong>date_debut</strong>, <strong>date_fin</strong> (facultatives, JJ/MM/AAAA) : à défaut, la période ci-dessous s'applique</li>
        </ul>
        <p><strong>Document libre</strong> : PDF (avec texte), fichier .txt, texte collé ou photo du programme.
            Une pharmacie par ligne ; les noms sont rapprochés de façon approximative de celles de la ville choisie
            (accents, « Phcie », fautes de frappe…). Les lignes douteuses ou non reconnues ne sont <strong>jamais</strong> publiées
            sans que vous choisissiez la pharmacie à l'étape suivante.</p>
        @unless($ocr)
            <p class="muted">Photos : OCR non installé. Collez le texte ou utilisez un PDF texte.</p>
        @endunless
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
        <label for="fichier">Fichier (CSV, Excel, PDF, TXT{{ $ocr ? ', image' : '' }})</label>
        <input type="file" id="fichier" name="fichier" accept=".csv,.txt,.xlsx,.xls,.pdf{{ $ocr ? ',.png,.jpg,.jpeg,.webp,.bmp,.tif,.tiff' : '' }}">
        <label for="texte">… ou collez le texte du programme</label>
        <textarea id="texte" name="texte" rows="8" placeholder="Une pharmacie par ligne, par exemple :&#10;Pharmacie de la Paix - Cocody&#10;PHCIE LES LAGUNES  27 22 44 55 66">{{ old('texte') }}</textarea>
        <div class="actions"><button class="btn">Analyser le fichier</button></div>
    </form>
@endsection
