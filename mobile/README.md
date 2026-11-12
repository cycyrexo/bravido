# BRAVIDO — application mobile (Flutter)

Android 8+ / iOS 14+, une seule base de code.

## 1. Générer les dossiers natifs (une seule fois)

Le code Dart (`lib/`) est fourni ; les dossiers `android/` et `ios/` se génèrent :

```
cd mobile
flutter create --org ci.bravido --project-name bravido --platforms android,ios .
flutter pub get
```

`flutter create` ne remplace pas les fichiers existants (`lib/`, `pubspec.yaml`, `test/`).
En cas de conflit de versions : `flutter pub upgrade --major-versions`.

## 2. Permissions

**Android** — `android/app/src/main/AndroidManifest.xml`, avant `<application>` :

```xml
<uses-permission android:name="android.permission.INTERNET"/>
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION"/>
<uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>
<queries>
    <intent><action android:name="android.intent.action.DIAL"/><data android:scheme="tel"/></intent>
    <intent><action android:name="android.intent.action.SENDTO"/><data android:scheme="sms"/></intent>
    <intent><action android:name="android.intent.action.VIEW"/><data android:scheme="https"/></intent>
</queries>
```

En développement uniquement (API en HTTP sur WAMP), ajouter `android:usesCleartextTraffic="true"` sur `<application>`.

Dans `android/app/build.gradle(.kts)` : `minSdk = 26` (Android 8).

**iOS** — `ios/Runner/Info.plist` :

```xml
<key>NSLocationWhenInUseUsageDescription</key>
<string>BRAVIDO utilise votre position pour trouver les pharmacies proches et la transmettre à vos complices en cas d'alerte.</string>
<key>LSApplicationQueriesSchemes</key>
<array><string>tel</string><string>sms</string></array>
```

Dans `ios/Podfile` : `platform :ios, '14.0'`.

## 3. Firebase (notifications push)

L'application fonctionne sans Firebase : les alertes partent alors par SMS.
Pour activer le push :

```
dart pub global activate flutterfire_cli
flutterfire configure
```

puis placer le JSON du compte de service côté serveur (`FCM_PROJECT_ID`, `FCM_CREDENTIALS` dans `backend/.env`).

## 4. Lancer

```
flutter run --dart-define=API_URL=http://10.0.2.2/bravido/backend/public/api/v1
```

- Émulateur Android : `10.0.2.2` = la machine hôte (WAMP).
- Téléphone réel : l'IP locale du PC, par ex. `http://192.168.1.20/bravido/backend/public/api/v1`.
- Production : `https://…/api/v1` (HTTPS obligatoire).

En local, le code OTP est `123456` (variable `OTP_CODE_TEST` du backend, ignorée hors `APP_ENV=local`).

## Organisation

```
lib/
  config.dart            URL de l'API, numéros d'urgence embarqués (hors ligne)
  core/                  API (Dio), session, cache hors ligne, géolocalisation, push
  screens/               accueil, alerte, pharmacies, établissements, espace utilisateur
  widgets/               composants partagés (bouton SOS, carte OSM, carrousel pub…)
```

## Limites connues du MVP

- Le partage de position pendant l'alerte tourne tant que l'écran de suivi est ouvert.
  Un suivi en arrière-plan (service de premier plan Android, mode « location » iOS) est à ajouter avant la publication.
- Les tuiles OpenStreetMap publiques ne conviennent pas à un trafic de production : prévoir un fournisseur de tuiles (ou Google Maps).
