# Tester l'application mobile BRAVIDO sur un vrai téléphone

Deux méthodes. Choisissez selon ce que vous voulez vérifier :

| | Méthode 1 : Wi-Fi local (PC) | Méthode 2 : serveur VPS |
|---|---|---|
| Téléphone | Android réel, **même Wi-Fi** que le PC | Android (et iOS avec compte Apple) |
| Exposition Internet | **Aucune** | Oui (HTTPS), c'est le but |
| Coût | 0 | VPS + domaine (voir `README-DEPLOIEMENT.md`) |
| Usage | Développement, démonstration à proximité | Testeurs à distance, test grandeur nature |

URL de l'API : l'application la lit à la compilation via `--dart-define=API_URL=...` (`mobile/lib/config.dart`,
valeur par défaut `http://10.0.2.2/bravido/backend/public/api/v1` = émulateur Android → WAMP de la machine hôte).

> **HTTP en clair : où c'est permis ?** Le manifeste **debug** (`mobile/android/app/src/debug/AndroidManifest.xml`) contient
> `android:usesCleartextTraffic="true"` : une version *debug* peut donc parler en `http://` à votre PC.
> Le manifeste **principal** (`src/main/AndroidManifest.xml`, utilisé par les versions *release*) ne l'autorise pas et ne définit
> aucun `networkSecurityConfig` : Android interdit alors le trafic HTTP non chiffré. **Une version release exige donc du HTTPS**
> (c'est voulu : les données de santé et de position ne doivent pas circuler en clair).

---

## Méthode 1 : téléphone Android sur le même Wi-Fi que le PC (sans exposition Internet)

### 1.1 Trouver l'adresse IP du PC sur le réseau local

PowerShell : `ipconfig` → carte « Wi-Fi » → **Adresse IPv4** (ex. `192.168.1.25`).
Le PC et le téléphone doivent être sur le **même** réseau Wi-Fi (pas le « Wi-Fi invité » isolé, pas la 4G).
Conseil : réservez une IP fixe pour le PC dans votre box/routeur pour qu'elle ne change pas.

### 1.2 Autoriser WAMP à répondre au réseau local (à décrire, **non modifié ici**)

Par défaut WAMP n'accepte que `localhost`. Deux possibilités, à faire **vous-même** si vous le décidez :

- Icône WAMP → **« Mettre en ligne »** (*Put Online*) : c'est le réglage le plus simple ; il accepte les connexions des autres machines.
- Ou, manuellement : dans `httpd-vhosts.conf` / `httpd.conf`, le dossier `www` (ou le vhost) utilise `Require local` ; le remplacer par
  `Require ip 192.168.1` (votre sous-réseau, **plus prudent**) ou `Require all granted` (tout le monde pouvant atteindre le port 80), puis redémarrer Apache.

Préférez toujours la restriction au sous-réseau local (`Require ip 192.168.1`). Ne redirigez **aucun port** depuis votre box et ne créez aucun tunnel : le but est un test purement local.
Remettez le réglage d'origine (« Mettre hors ligne ») après le test.

### 1.3 Pare-feu Windows : port 80, réseaux *privés* uniquement

PowerShell **en administrateur** :

```powershell
New-NetFirewallRule -DisplayName "BRAVIDO test Wi-Fi (HTTP 80, prive)" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow -Profile Private
```

Vérifiez que votre réseau Wi-Fi est classé **« Privé »** (Paramètres → Réseau et Internet → Wi-Fi → propriétés du réseau → type de profil : *Réseau privé*).
Pour supprimer la règle ensuite : `Remove-NetFirewallRule -DisplayName "BRAVIDO test Wi-Fi (HTTP 80, prive)"`.

### 1.4 Régler `APP_URL` du backend

Dans `backend\.env` : `APP_URL=http://192.168.1.25/bravido/backend/public` (votre IP), puis `php artisan config:clear`.
Sans cela, les **liens envoyés par SMS** (suivi de trajet, alerte) et les liens de carte pointeront vers `localhost`, inaccessible depuis le téléphone.

Test rapide depuis le **navigateur du téléphone** : `http://192.168.1.25/bravido/backend/public/api/v1/sante` doit afficher du JSON.

### 1.5 Compiler et installer

```powershell
cd C:\wamp64\www\bravido\mobile
flutter build apk --debug --dart-define=API_URL=http://192.168.1.25/bravido/backend/public/api/v1
```

Le fichier est créé dans `mobile\build\app\outputs\flutter-apk\app-debug.apk`.

- **Par câble USB** : sur le téléphone, activez les *Options pour les développeurs* (Paramètres → À propos → toucher 7 fois « Numéro de build ») puis **Débogage USB** ; branchez, acceptez l'empreinte RSA, puis
  `adb devices` (le téléphone doit apparaître) et `adb install -r build\app\outputs\flutter-apk\app-debug.apk`.
  (Ou directement : `flutter run --dart-define=API_URL=http://192.168.1.25/bravido/backend/public/api/v1`.)
- **Sans câble** : envoyez `app-debug.apk` au téléphone (partage Wi-Fi, e-mail, cloud privé), ouvrez-le et autorisez « Installer des applications inconnues » pour l'application utilisée.

Le code OTP fixe `123456` fonctionne tant que `APP_ENV=local` (`OTP_CODE_TEST` dans le `.env` local).
Les notifications push exigent `google-services.json` dans `mobile/android/app/` (déjà utilisé pour les builds locaux, **jamais** dans git).

---

## Méthode 2 : contre le serveur VPS (HTTPS)

Prérequis : serveur installé selon `README-DEPLOIEMENT.md`, `https://api.<domaine>/api/v1/sante` répond 200.

### 2.1 Créer **votre propre** clé de signature (une seule fois)

L'assistant ne génère ni clé ni mot de passe : c'est à vous de le faire, et de **conserver la clé en lieu sûr** (si vous la perdez, vous ne pourrez plus mettre à jour l'application sur le Play Store).

```powershell
cd C:\wamp64\www\bravido\mobile\android
keytool -genkey -v -keystore bravido-upload.keystore -alias bravido -keyalg RSA -keysize 2048 -validity 10000
```

`keytool` (fourni avec le JDK / Android Studio) vous demande un mot de passe et votre identité : choisissez un mot de passe fort et notez-le dans un gestionnaire de mots de passe.
Créez ensuite `mobile\android\key.properties` (fichier **ignoré par git**, comme `*.keystore`) :

```properties
storePassword=<votre mot de passe>
keyPassword=<votre mot de passe>
keyAlias=bravido
storeFile=../bravido-upload.keystore
```

### 2.2 Modifier `mobile/android/app/build.gradle.kts` (extrait à adapter)

En haut du fichier :

```kotlin
import java.util.Properties
import java.io.FileInputStream

val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}
```

Dans le bloc `android { ... }`, **avant** `buildTypes` :

```kotlin
signingConfigs {
    create("release") {
        keyAlias = keystoreProperties["keyAlias"] as String
        keyPassword = keystoreProperties["keyPassword"] as String
        storeFile = file(keystoreProperties["storeFile"] as String)
        storePassword = keystoreProperties["storePassword"] as String
    }
}
```

Et remplacez la ligne actuelle `signingConfig = signingConfigs.getByName("debug")` dans `buildTypes { release { ... } }` par :

```kotlin
signingConfig = signingConfigs.getByName("release")
```

(Tant que cette modification n'est pas faite, un build *release* est signé avec la clé de debug : il s'installe pour des tests, mais il est **refusé par le Play Store**.)

### 2.3 Compiler la version release

```powershell
cd C:\wamp64\www\bravido\mobile
flutter build apk --release --dart-define=API_URL=https://api.<domaine>/api/v1
# Pour le Play Store, on envoie un « app bundle » (AAB) plutôt qu'un APK :
flutter build appbundle --release --dart-define=API_URL=https://api.<domaine>/api/v1
```

Sorties : `build\app\outputs\flutter-apk\app-release.apk` et `build\app\outputs\bundle\release\app-release.aab`.
Pour chaque nouvelle version envoyée aux testeurs, augmentez le numéro dans `mobile/pubspec.yaml` (`version: 1.0.0+2` : le nombre après `+` doit croître).

### 2.4 Distribuer aux testeurs

1. **APK partagé directement** (le plus simple pour 2 à 5 testeurs) : envoyez `app-release.apk` par un lien privé (Drive, WeTransfer…) ; chaque testeur autorise l'installation depuis cette source. Aucune mise à jour automatique.
2. **Firebase App Distribution** (gratuit, recommandé) : console Firebase → *App Distribution* → ajoutez les e-mails des testeurs → envoyez l'APK ; les testeurs reçoivent un lien et les mises à jour. En ligne de commande : `firebase appdistribution:distribute app-release.apk --app <ID-application-Firebase> --testers "a@x.ci,b@y.ci"`.
3. **Google Play Console, piste « Test interne »** : compte développeur Google Play (25 USD, paiement unique) ; créez l'application, envoyez l'AAB sur la piste *Test interne*, ajoutez jusqu'à 100 testeurs par e-mail ; ils installent depuis le Play Store via un lien. Il faut remplir la fiche de l'application, la politique de confidentialité (données de santé et de localisation : **obligatoire et sensible**) et le formulaire « Sécurité des données ».
4. **iOS** : nécessite un **compte Apple Developer (99 USD/an)** et un Mac **ou** un service d'intégration continue dans le cloud. Le dépôt contient déjà une configuration pour **Codemagic** (build iOS dans le cloud) : créez le compte Codemagic, reliez le dépôt, ajoutez dans Codemagic vos certificats/profils Apple et `GoogleService-Info.plist` (variables chiffrées, jamais dans git), puis publiez sur **TestFlight** (testeurs internes immédiats, externes après une revue Apple allégée). Les notifications push iOS exigent en plus une clé APNs liée à Firebase.

### 2.5 Points de contrôle avant d'inviter des testeurs

- `https://api.<domaine>/api/v1/sante` → 200.
- Dans l'application, l'inscription par SMS fonctionne : en `SMS_DRIVER=log` aucun SMS n'est envoyé ; lisez le code dans `storage/logs/` côté serveur (ou configurez un vrai agrégateur SMS, voir `README-DEPLOIEMENT.md` étape 16). **Le code fixe `123456` ne fonctionne pas en production.**
- Le worker supervisor tourne (sinon pas de notification d'alerte).
- Pour des tests réalistes de l'alerte SOS, prévenez vos testeurs et utilisez un numéro de contact de confiance : une alerte déclenche de vraies notifications/SMS si un agrégateur est configuré.
