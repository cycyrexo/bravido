# BRAVIDO · Partout et tout le temps

Application mobile de santé et d'urgence (Côte d'Ivoire) : alerte aux 7 complices, numéros d'urgence,
pharmacies de garde, établissements de soins avec assurances acceptées, espace utilisateur et back-office.

```
backend/   API REST + back-office web (Laravel 12, PHP 8.2+, MySQL 8)
mobile/    Application Android / iOS (Flutter)
```

## Backend : installation sur WAMP

Prérequis : PHP 8.2+ (extensions `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `gd`), MySQL 8, Composer.

```
cd C:\wamp64\www\bravido\backend
composer install
copy .env.example .env
php artisan key:generate
```

Créer les bases `bravido` et `bravido_test` (utf8mb4) dans phpMyAdmin, ajuster `DB_*` dans `.env`, puis :

```
php artisan migrate --seed
php artisan storage:link
```

- Back-office : http://localhost/bravido/backend/public/admin (identifiants `ADMIN_EMAIL` / `ADMIN_PASSWORD` du `.env`)
- API : http://localhost/bravido/backend/public/api/v1/config
- En local, le seeder ajoute des données **fictives** (préfixe « DÉMO ») et le code OTP vaut `123456`.

Mettre `APP_URL` à l'adresse réelle : elle sert aux liens envoyés par SMS (carte de l'alerte, invitation).

### Processus à faire tourner

| Processus | Commande | Rôle |
|---|---|---|
| File d'attente | `php artisan queue:work --queue=alertes,default` | Envoi des alertes (push puis SMS de secours) |
| Planificateur | `php artisan schedule:run` chaque minute | Clôture des alertes après 1 h, purge des positions après 30 jours |

En développement : `php artisan queue:work` et `php artisan schedule:work` dans deux terminaux.
En production : Supervisor (ou service Windows) pour la file, cron (ou Planificateur de tâches) pour `schedule:run`.

### Exploitation (supervision, sauvegardes, santé)

- **Santé** : `GET /api/v1/sante` (public, minimal : `status`, `db`, `queue`, `scheduler`) et carte « Santé du système » du tableau de bord (admin) : base, file d'attente (alerte si le plus ancien travail attend plus de 120 s), travaux en échec, battement du planificateur (`bravido:heartbeat` chaque minute ; alerte si > 3 min), FCM, espace disque (< 1 Go).
- **Planificateur** (indispensable : clôture des alertes, trajets, purge, battement, expiration des abonnements) :
  `schtasks /Create /TN "BRAVIDO schedule" /SC MINUTE /MO 1 /TR "C:\wamp64\bin\php\php8.5.0\php.exe C:\wamp64\www\bravido\backend\artisan schedule:run"`
- **Surveillance du worker** : `outils\surveillance.ps1` interroge `/sante` et relance `backend\demarrer-worker.ps1` si la file est bloquée ou si aucun worker ne tourne. À enregistrer une fois (non fait automatiquement), toutes les minutes :
  `schtasks /Create /TN "BRAVIDO surveillance" /SC MINUTE /MO 1 /RL HIGHEST /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\wamp64\www\bravido\outils\surveillance.ps1"` (journal : `backend\storage\logs\surveillance.log`).
- **Sauvegardes** : base `bravido` (`mysqldump`) chaque nuit et dossier `backend\storage\app` (messages vocaux, pièces, photos) ; conserver au moins 7 jours hors du serveur. Journaux dans `backend\storage\logs`.
- **Conservation** (`bravido:purger`, 02:30) : positions d'alerte, messages vocaux et trajets 30 jours ; journal d'audit des alertes 12 mois ; positions proposées 90 jours.
- **Paiement** : variables `PAIEMENT_SANDBOX` (mettre `false` en production) et clés des fournisseurs dans `.env` (voir `.env.example`).

### Tests

```
php artisan test            # backend (base MySQL bravido_test, voir phpunit.xml)
cd ../mobile && flutter analyze lib && flutter test
```

L'intégration continue (`.github/workflows/tests.yml`) lance les deux (PHP 8.2 + MySQL, Flutter).

### Fonctions de la vague 4

| Fonction | Résumé |
|---|---|
| Journal d'audit des alertes (F22) | Table `alerte_evenements` sans donnée personnelle ; détail et export CSV au back-office |
| Supervision (F23) | `/sante`, carte du tableau de bord, `outils\surveillance.ps1`, tests Flutter à API simulée |
| Hors ligne (F20) | `GET /villes/{id}/export` (version, ETag, gzip) ; Réglages > Utiliser hors ligne ; moteur de recherche local (`mobile/lib/core/moteur_local.dart`, synonymes générés par `php outils\generer-synonymes.php`). Fonds de carte non mis en cache |
| Avis et notes (F13) | Pré-modération au back-office, réponses des responsables, signalements ; aucun avis sur les centres de secours et bureaux FGA |
| Rapports d'erreurs (F21) | Crashlytics désactivé par défaut, interrupteur dans Réglages ; à activer une fois dans la console Firebase |
| Abonnement famille (F19) | Plans, paiements sandbox signés (HMAC), adaptateurs Orange Money / MTN MoMo / Wave vides ; les alertes ne dépendent jamais d'un abonnement |

## Ce qui est livré (lot MVP du cahier des charges)

| Exigence | Où |
|---|---|
| ACC-01 à 04 accueil, carrousel, SOS, ville | `mobile/lib/screens/home_screen.dart` |
| URG-01 complices (7 max, consentement) | `backend/app/Services/ComplicesService.php` |
| URG-02/03 appui 3 s, compte à rebours 5 s, position 30 s pendant 1 h, push + SMS de secours | `AlerteService`, `Jobs/`, `alerte_screen.dart` |
| URG-04 suivi par complice | `AlerteController`, `alerte_active_screen.dart` |
| URG-05/06/07 numéros hors ligne, centres GSPM / ONPC, bureaux FGA | `ReferentielController`, `etablissements_screen.dart` |
| PHA-01 à 04 pharmacies, gardes datées, fiche, filtre assurance | `EtablissementController`, `pharmacies_screen.dart` |
| PHA-05 favoris et gardes hors ligne (V2, livré) | `FavoriController`, cache `gardes_{ville}` |
| HOP-01 à 03 catégories, recherche service / assurance, signaler une erreur | idem |
| USR-01 à 04 OTP, messages, call center, fiches | `AuthController`, `MessageSupportController`, `FicheController` |
| BO-01 à 06 tableau de bord, établissements, import des gardes, fiches, support, régie | `app/Http/Controllers/Admin`, `resources/views/admin` |
| Rôles, journal des actions | middleware `role`, table `journal_admin` |

Non livré (lot V2) : publications modérées (URG-08, BO-07), fiches Premium, abonnement famille.

## Choix techniques

- **Distance** : colonnes `lat` / `lng` indexées, pré-filtre par boîte englobante puis tri par Haversine en SQL.
  Compatible MySQL et MariaDB (WAMP peut utiliser l'un ou l'autre) et sans piège d'ordre des axes du SRID 4326.
- **Gardes** : périodes datées `date_debut` / `date_fin` (hebdomadaire ou mensuelle). L'import crée d'abord un aperçu,
  détecte les doublons et les chevauchements, puis publie.
- **Alerte** : file `alertes` dédiée ; push FCM d'abord, SMS de secours si le complice n'a pas l'application
  ou si le push n'est pas accusé dans les 20 s (`ALERTE_DELAI_SMS_S`).
- **Sécurité** : OTP haché, 1 code par minute et 5 par heure, 5 essais par code ; jetons Sanctum ;
  pièces jointes sur disque privé ; positions supprimées après 30 jours ; journal des actions du back-office.

## À faire avant la mise en production

1. Brancher la vraie passerelle SMS (adapter `app/Services/Sms/HttpSmsSender.php` au contrat de l'agrégateur).
2. Configurer Firebase (mobile + compte de service serveur).
3. Vérifier les numéros d'urgence et la liste des assureurs (`database/seeders/ReferentielSeeder.php`).
4. Suivi de position en arrière-plan côté mobile (voir `mobile/README.md`).
5. Déclaration ARTCI, politique de confidentialité, charte annonceurs.
6. Traduire les messages de validation Laravel en français (`composer require laravel-lang/common --dev`).
