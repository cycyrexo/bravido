@extends('public.layout')
@section('titre', $titre)

@section('contenu')
    <div class="carte">
        <h1 style="margin:0 0 8px;font-size:22px">{{ $titre }}</h1>
        <p>{{ $texte }}</p>
    </div>
@endsection
