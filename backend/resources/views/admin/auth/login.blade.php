<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connexion · BRAVIDO</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
<div class="login carte">
    <h1 class="login-logo"><img src="{{ asset('img/logo-bravido.png') }}" alt="BRAVIDO"><span>Back-office</span></h1>
    @if($errors->any())
        <div class="flash erreurs">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('admin.login') }}">
        @csrf
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <div class="cases" style="margin-top:12px"><label><input type="checkbox" name="remember" value="1"> Rester connecté</label></div>
        <div class="actions"><button class="btn">Se connecter</button></div>
    </form>
</div>
</body>
</html>
