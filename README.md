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

### Tests

```
php artisan test
```

(base MySQL `bravido_test`, voir `phpunit.xml`)

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
