# Comment tester BRAVIDO

Ce guide s'adresse à quelqu'un qui n'est pas développeur. Aucune commande à taper.

## 1. Tout démarrer (1 double-clic)

1. Ouvrez le dossier `C:\wamp64\www\bravido`.
2. Double-cliquez sur **`LANCER-BRAVIDO.bat`**.
3. Une fenêtre noire affiche 7 étapes. Chaque étape réussie est marquée **OK** en vert.
   - Le premier démarrage du téléphone virtuel prend 1 à 3 minutes. C'est normal.
   - Si le message vous demande de lancer WAMP : ouvrez **Wampserver64** depuis le menu Démarrer, attendez que son icône (en bas à droite, près de l'horloge) devienne **verte**. Le script reprend tout seul.
4. À la fin, vous avez :
   - le **téléphone virtuel** (fenêtre Android) avec l'application BRAVIDO ouverte ;
   - le **back-office** ouvert dans votre navigateur.

Vous pouvez relancer `LANCER-BRAVIDO.bat` à tout moment, même si tout est déjà allumé : rien n'est cassé, c'est remis en ordre.

**Informations utiles**

| Quoi | Valeur |
|---|---|
| Back-office | http://localhost/bravido/backend/public/admin/login |
| Identifiant back-office | `admin@bravido.ci` |
| Mot de passe back-office | `ChangezMoi!2026` |
| Code reçu par « SMS » (mode test) | toujours **`123456`** |
| Position du téléphone virtuel | Abidjan (Plateau) |

> Aucun vrai SMS n'est envoyé en mode test. Les SMS sont écrits dans le fichier
> `C:\wamp64\www\bravido\backend\storage\logs\laravel.log`
> (ouvrez-le avec le Bloc-notes, allez tout en bas, cherchez les lignes `local.INFO: [SMS]`).

> **Attention :** on ne peut demander qu'**un code par minute pour un même numéro**.
> Si l'application refuse d'envoyer le code, attendez 1 minute puis réessayez.

---

## 2. Scénarios de test

Cochez chaque ligne quand le résultat attendu est bien obtenu.

### A. Pharmacies de garde (sans compte)

| Étape | Action | Résultat attendu |
|---|---|---|
| A1 | Regardez l'écran d'accueil | Une carte **« Pharmacies de garde près de vous »** indique la période (ex. *Du 09/11 au 15/11 · 9 pharmacies à Abidjan*) |
| A2 | Touchez **Voir** (ou la tuile **Pharmacies Jour & Garde**) | La liste des pharmacies de garde s'affiche, de la plus proche à la plus éloignée, avec la distance |
| A3 | Utilisez les filtres **Toutes communes** / **Toutes assurances** | La liste se réduit selon le filtre choisi |
| A4 | Touchez une pharmacie | Sa fiche s'ouvre : nom, adresse, distance, téléphone, horaires |
| A5 | Touchez **Appeler** | L'écran d'appel du téléphone s'ouvre avec le numéro déjà composé (n'appelez pas réellement) |
| A6 | Revenez, touchez **Itinéraire** | Une carte / l'application d'itinéraire s'ouvre vers la pharmacie |
| A7 | Testez aussi la tuile **Hôpitaux & Cliniques** | Une liste d'établissements s'affiche |

### B. Créer un compte

| Étape | Action | Résultat attendu |
|---|---|---|
| B1 | Touchez la tuile **Espace Utilisateur** | Un champ **Numéro de téléphone** s'affiche (si un compte est déjà connecté, touchez d'abord **Se déconnecter** en bas) |
| B2 | Tapez un numéro **nouveau**, par exemple `07 11 22 33 44`, puis validez | Le message *« Code envoyé au … »* s'affiche |
| B3 | Tapez le code **`123456`**, puis **Valider** | Vous êtes connecté : l'espace utilisateur affiche *« Complétez votre profil »* et les rubriques (Mes complices, Fiche de souscription…) |
| B4 | Touchez votre nom / *Complétez votre profil*, saisissez un nom et une ville, **Enregistrer** | Le nom apparaît en haut de l'espace utilisateur |

Si le code est refusé : vérifiez que vous avez bien tapé `123456`. Après 5 erreurs, le code est bloqué : redemandez-en un (après 1 minute).

### C. Inviter un complice

Restez connecté avec le compte créé en B.

| Étape | Action | Résultat attendu |
|---|---|---|
| C1 | **Espace Utilisateur > Mes complices** | L'écran *Mes complices* s'affiche (0 complice) |
| C2 | Saisissez un **Nom** (ex. `Awa`) et un **Téléphone** (ex. `07 55 66 77 88`), puis touchez **Inviter** | Le complice apparaît avec le statut **en attente** |
| C3 | Ouvrez `laravel.log` (voir plus haut), dernière ligne `local.INFO: [SMS]` | Une ligne *« … souhaite vous prévenir en cas d'urgence. Acceptez ou refusez ici : http://localhost/bravido/backend/public/invitation/… »* |
| C4 | Copiez ce lien et ouvrez-le dans le navigateur du PC, puis cliquez **J'accepte** | Une page de confirmation s'affiche |
| C5 | Dans l'application, revenez sur **Mes complices** (quittez l'écran et revenez) | Le complice est maintenant **accepté** |

Une personne peut avoir **7 complices au maximum**.

### D. Back-office

Dans le navigateur, connectez-vous avec `admin@bravido.ci` / `ChangezMoi!2026`.

**D1. Valider une fiche établissement**

1. Dans l'application (connecté), **Espace Utilisateur > Fiche de souscription / correction > Inscrire un établissement**.
2. Remplissez au minimum **Dénomination**, **Catégorie**, **Commune**, touchez **Je suis sur place : utiliser ma position**, puis **Envoyer pour validation**.
   Résultat attendu : *« Fiche envoyée. Notre équipe va la vérifier. »*
3. Dans le back-office, menu **Fiches** : la fiche apparaît (un compteur s'affiche à côté du menu).
4. Ouvrez-la, vérifiez les informations, cliquez **Valider la fiche**.
   Résultat attendu : la fiche passe en *validée* ; l'établissement apparaît dans le menu **Établissements** et, dans l'application, *Mes fiches établissement* affiche **Validée**.
   (Vous pouvez aussi tester **Rejeter** avec un motif : le motif s'affiche dans l'application.)

**D2. Publier un programme de garde**

1. Menu **Gardes**, puis la saisie du **Programme de garde**.
2. Choisissez la ville **Abidjan**, la période (**Début** / **Fin**), cliquez **Afficher**.
3. Cochez les pharmacies de garde, cliquez **Publier le programme**.
   Résultat attendu : message de confirmation. Dans l'application, fermez puis rouvrez l'accueil : la carte *Pharmacies de garde* reflète le nouveau programme (période et nombre) si la période inclut aujourd'hui.
   (Il existe aussi **Importer un programme de garde** depuis un fichier Excel/CSV, avec un modèle téléchargeable.)

**D3. Créer une bannière publicitaire**

1. Menu **Publicité > Nouvelle bannière**.
2. Remplissez **Annonceur**, **Début** (aujourd'hui), **Fin** (dans une semaine), choisissez une **Image** (1200 × 400 px conseillé, 2 Mo max), cochez **Active**, **Enregistrer**.
   Résultat attendu : la bannière apparaît dans la liste ; dans l'application, elle s'affiche en carrousel (fermez/rouvrez l'application si besoin).

### E. Tester une alerte SOS

Une alerte complète a besoin de **deux comptes qui sont complices l'un de l'autre**. Deux comptes de test existent déjà et sont **déjà complices acceptés mutuellement** :

| Compte | Numéro | Rôle dans ce test |
|---|---|---|
| A | `07 00 00 01 01` | déclenche l'alerte sur le téléphone virtuel |
| B | `07 00 00 01 02` | complice qui reçoit l'alerte |

| Étape | Action | Résultat attendu |
|---|---|---|
| E1 | **Espace Utilisateur** > **Se déconnecter** (si un autre compte est connecté) | L'écran de connexion s'affiche |
| E2 | Connectez-vous avec `07 00 00 01 01`, code `123456` | Connecté. **Mes complices** affiche B comme accepté |
| E3 | Revenez à l'accueil, touchez **SOS · Alerter mes complices** | L'écran SOS s'affiche : *« Maintenez le bouton 3 secondes pour alerter 1 complice »* |
| E4 | Choisissez un **Motif** (ex. Malaise), puis **maintenez** le gros bouton rouge **SOS** 3 secondes | Un compte à rebours *« Envoi de l'alerte dans … »* apparaît (bouton **Annuler** disponible) |
| E5 | Laissez le compte à rebours finir | L'écran de suivi de l'alerte s'affiche (*Votre alerte est en cours*) |
| E6 | Back-office, menu **Alertes** (*Journal des alertes*) | Une nouvelle ligne : heure, motif, complices, remise, SMS de secours, réponses, statut **en cours** |
| E7 | Ouvrez `laravel.log`, dernière ligne `local.INFO: [SMS]` | *« ALERTE BRAVIDO : … a besoin d'aide (Malaise). Position : http://localhost/bravido/backend/public/a/… »* adressé au `+2250700000102` |
| E8 | Ouvrez ce lien « Position » dans le navigateur | Une carte montre la position de la personne en alerte (Abidjan) |
| E9 | Dans l'application, touchez **Je suis en sécurité**, puis **Oui** | L'alerte est clôturée ; dans le back-office (rechargez la page), son statut change |

(Pour tester une erreur de manipulation : **Fausse manipulation : annuler l'alerte**.)

> Pour voir l'alerte **du côté du complice** dans l'application, déconnectez-vous et reconnectez-vous
> avec le compte B (`07 00 00 01 02`) pendant que l'alerte est en cours : la page **Alerte & Urgence**
> affiche *« … a besoin d'aide »*. Rappel : 1 minute d'attente entre deux codes pour un même numéro.

> **Important :** BRAVIDO ne remplace pas les secours. N'appelez pas réellement le 185 ou le 180 pendant les tests.

---

## 3. En cas de problème

| Problème | Que faire |
|---|---|
| L'application affiche « Pas de connexion » ou des listes vides | Vérifiez que l'icône WAMP est verte, puis relancez `LANCER-BRAVIDO.bat` |
| Le téléphone virtuel est figé / écran noir | Fermez la fenêtre de l'émulateur, relancez `LANCER-BRAVIDO.bat` |
| « Code déjà envoyé » / refus d'envoyer le code | Attendez 1 minute avant de redemander un code pour le même numéro |
| Le back-office refuse le mot de passe | Vérifiez majuscules et le `!` : `ChangezMoi!2026` |
| La fenêtre noire affiche **ECHEC** en rouge | Lisez le message, corrigez (souvent : lancer WAMP), relancez. Sinon, faites une capture d'écran de la fenêtre et envoyez-la à l'équipe technique |
