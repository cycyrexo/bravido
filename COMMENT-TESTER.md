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

> **Important :** la base contient désormais uniquement les 1 687 établissements réels (annuaire GUIDSANTE) et **aucun programme de garde**. Tant qu'aucun programme n'est publié, la carte « Pharmacies de garde » est vide et la liste de garde n'affiche aucune pharmacie : c'est normal. Pour tester cette section, commencez par la section **D2** (back-office : **Gardes**, choisir la ville et la période, cocher quelques pharmacies d'Abidjan, **Publier le programme**), puis rouvrez l'application.

| Étape | Action | Résultat attendu |
|---|---|---|
| A1 | Regardez l'écran d'accueil | Une carte **« Pharmacies de garde près de vous »** indique la période (après publication d'un programme en D2, ex. *Du 09/11 au 15/11 · 5 pharmacies à Abidjan*) |
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
3. Cochez les pharmacies de garde (pour tester, quelques pharmacies d'Abidjan suffisent), cliquez **Publier le programme**. Il n'y a plus de programme préchargé : tant que vous n'en publiez pas, le tableau de bord affiche le bandeau rouge « Aucune pharmacie de garde en cours ».
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

### F. Nouvelles fonctions de sécurité

Utilisez toujours les comptes A (`07 00 00 01 01`) et B (`07 00 00 01 02`), complices acceptés l'un de l'autre. Aucune de ces fonctions n'est « invisible » ni automatique en cachette : tout geste reste volontaire.

| Fonction | Où | Comment tester | Résultat attendu |
|---|---|---|---|
| **Fiche médicale** | Espace Utilisateur > **Ma fiche médicale** | Remplissez (allergies, traitements…), activez l'interrupteur de partage, **Enregistrer** | Enregistrée. Si A déclenche une alerte, B voit la carte **Infos médicales** sur l'alerte reçue ; interrupteur coupé ou alerte terminée : la carte disparaît. Jamais dans les SMS ni le back-office |
| **Message vocal** | Écran de suivi d'une alerte active (compte A) | **Enregistrer un message vocal** > **Commencer** (30 s max, voyant d'enregistrement visible) > **Envoyer** | B reçoit une notification « nouveau message vocal » et peut l'écouter sur l'alerte reçue. Supprimé après 30 jours |
| **Je rentre chez moi** | Accueil > tuile **Je rentre chez moi** | Choisissez 15 min, cochez B, **Démarrer**. Puis **+ 15 min** (2 fois max) ou **Je suis arrivé(e)** | Compte à rebours. Sans confirmation à l'échéance : rappel, puis 2 min plus tard alerte automatique à B (motif Autre, « Trajet non confirmé »). *Test rapide : voir la ligne de commande ci-dessous* |
| **Faux appel** | Accueil > tuile **Faux appel** | Réglez le nom (« Maman » par défaut), **Lancer le faux appel**, **Répondre** | Écran d'appel entrant, puis fausse conversation avec chronomètre. **Appui long de 2 s sur le chronomètre** = vraie alerte envoyée sans son ni vibration (à ne tester que si vous acceptez d'alerter B) |
| **Mode discret** | Espace Utilisateur (ou écran Faux appel) > **Mode discret** | Activez, puis SOS : maintenez 3 s | Le compte à rebours devient un écran sombre de 3 s, sans vibration ; toucher l'écran annule |

Surveillance des trajets (planifiée chaque minute par le serveur) : `php artisan bravido:verifier-trajets`.
Ne déclenchez pas de vraie alerte SOS depuis le téléphone pour ces essais, sauf si vous le souhaitez.

### G. Vague 2 : positions, espace responsable, fiche Premium

Back-office : `http://localhost/bravido/backend/public/admin` (menu de gauche : **Géolocalisation**, **Déclarations de garde**, **Fiches**, **Fiches Premium** réservé à l'administrateur). Dans l'émulateur, réglez une position à Abidjan : `adb emu geo fix -4.0083 5.36`.

| Fonction | Où | Comment tester | Résultat attendu |
|---|---|---|---|
| **Je suis sur place** (position confirmée) | App : Pharmacies > **Toutes les pharmacies** > ouvrir une fiche « Position approximative » | **Je suis sur place : confirmer la position** > **Confirmer** (phrase de consentement) | « Merci, position transmise ». Une seule fois par utilisateur et par établissement. Avec 3 utilisateurs distincts à moins de 60 m l'un de l'autre, le back-office > **Géolocalisation** > **Propositions des utilisateurs** affiche le point médian sur la carte : **Valider** (position précise) ou **Rejeter**. Jamais publié automatiquement. Propositions supprimées après décision ou 90 jours |
| **Je gère cet établissement** | App : fiche > **Je gère cet établissement** | Indiquez votre fonction, joignez un justificatif (facultatif), **Envoyer la demande**. Back-office > **Fiches** > type *revendication* > **Examiner** > **Valider** | L'utilisateur devient responsable (rôle `resp_etab`, accès normal conservé). Espace Utilisateur > **Mon établissement** apparaît |
| **Mon établissement** | App : Espace Utilisateur > **Mon établissement** | Ouvrez l'établissement : statistiques 30 jours (vues, appels, itinéraires), **Modifier ma fiche** (envoyée en modération), **Déclarer ma garde** (pharmacies : choisir la période, 14 jours max) | La modification et la garde restent « En attente » jusqu'à validation : Back-office > **Fiches** et **Déclarations de garde** > **Valider** (crée la garde, jamais de chevauchement) ou **Rejeter** avec un motif, visible par le responsable |
| **Fiche Premium** | Back-office > **Fiches Premium** | Rechercher un établissement > **Activer Premium** (date de fin) > ajouter jusqu'à 5 photos, horaires détaillés, description longue > **Enregistrer** | App : liste de la ville dans sa catégorie, la fiche apparaît en tête avec **À la une** (jamais pour les urgences ni sur les écrans d'alerte, 2 fiches maximum, ordre des autres inchangé). Fiche ouverte : carrousel de photos, horaires par jour, « À propos ». Date dépassée : tout disparaît |
| **Statistiques d'usage** | Back-office > **Fiches Premium** ; App > Mon établissement | Ouvrir une fiche, toucher **Appeler** / **Itinéraire** | Compteurs anonymes par jour (aucun identifiant d'utilisateur) |

Purge quotidienne (propositions de position > 90 jours, positions d'alerte, etc.) : `php artisan bravido:purger`.

### H. Vague 3 : recherche, carte, ouvert maintenant, partage, rappels, thème et langue

Aucune installation supplémentaire : relancer l'application (`adb install -r`) suffit. Côté serveur : `php artisan queue:restart` puis relancer `backend\demarrer-worker.ps1` si le code a changé.

| Fonction | Où | Comment tester | Résultat attendu |
|---|---|---|---|
| **Recherche globale** | App : accueil > barre arrondie sous le logo | Taper « dentiste », « phcie », « lunettes », « vétérinaire », « echographie » (accents et petites fautes tolérés) ; puces de catégorie ; rouvrir la recherche | Suggestions après 2 lettres ; résultats triés (nom exact, début de nom, contient, service ; fiches à position précise et proches d'abord), 40 maximum avec le décompte par catégorie ; recherches récentes effaçables ; états vide et erreur. API : `GET /api/v1/recherche?q=dentiste&ville_id=1`, `GET /api/v1/recherche/suggestions?q=pha` |
| **Carte** | App : accueil > tuile **Carte** | Bouger/zoomer ; puces de catégorie ; bouton « ma position » ; toucher un marqueur | Seuls les établissements à **position précise** sont affichés (regroupés en grappes au dézoom) ; bandeau « N établissements sans position exacte ne sont pas sur la carte » > liste ; fiche dans un volet. API : `GET /api/v1/etablissements/carte?bbox=sw_lat,sw_lng,ne_lat,ne_lng&categorie=&limit=300`. Pour voir des marqueurs en test : mettre `position_precise=1` sur quelques établissements (back-office > Géolocalisation) |
| **Ouvert maintenant** | App : pastille sur chaque carte d'établissement et sur la fiche | Ouvrir un établissement H24, un Premium avec horaires détaillés, un autre avec « Lun-Sam 8h-20h » | « Ouvert 24h/24 », « Ouvert · ferme à 20:00 », « Fermé · ouvre à 08:00 », « Ferme bientôt ». Horaires illisibles ou absents : **aucune pastille** (jamais de supposition). Fuseau Africa/Abidjan (UTC+0) |
| **Partager** | App : fiche d'un établissement > icône partage | Partager vers Notes/WhatsApp | Nom, catégorie, adresse/repère, commune, téléphone(s), lien Google Maps (position précise : coordonnées ; approximative : recherche nom + commune), garde si de garde, « via BRAVIDO » |
| **Mes rappels** | App : accueil > **Rappels** (ou Espace Utilisateur > **Mes rappels**) | **Nouveau rappel** : type médicament (heures) ou rendez-vous (date + heure, préavis), répétition (une fois, chaque jour, chaque semaine), titre/note ; activer/désactiver ; supprimer | Notifications locales (canal « Rappels »), uniquement sur le téléphone. Si « Alarmes et rappels » n'est pas accordé, bandeau « rappels approximatifs » (+ bouton **Autoriser les heures exactes**). Reprogrammés après redémarrage. Test rapide : rappel dans 2 minutes, `adb shell dumpsys alarm \| grep bravido` |
| **Thème, taille du texte, langue** | App : accueil > roue crantée (en haut à gauche) ou Espace Utilisateur > **Réglages** | Thème Système/Clair/Sombre ; taille Petit/Normal/Grand/Très grand ; langue Auto/Français/English ; mode discret | Effet immédiat sans redémarrer ; réglages mémorisés. English : accueil, alerte, SOS, pharmacies, hôpitaux, fiche, recherche, carte, rappels, espace utilisateur, trajet, faux appel, fiche médicale. Les données serveur (noms, services, horaires) restent telles que publiées. Par défaut : langue du téléphone si français/anglais, sinon français |
### Vague 4 (journal d'audit, supervision, hors ligne, avis, rapports d'erreurs, abonnement famille)

| Fonction | Où | Comment tester | Résultat attendu |
|---|---|---|---|
| **Journal d'audit des alertes** | Back-office > **Alertes** > cliquer l'heure d'une alerte ; bouton CSV (admin) | Après une alerte de test : ouvrir le détail | Chronologie (déclenchée, push, SMS de secours, reçue, vue, réponse, clôture), délais 1er push / 1re ouverture, SMS utilisé ou non. Aucune position, aucun message, aucun nom ni numéro. Conservé 12 mois (`bravido:purger`) |
| **Santé du système** | Back-office > Tableau de bord (admin) ; `GET /api/v1/sante` | Regarder la carte ; arrêter le worker 2 min | Base, file (alerte si > 120 s), travaux en échec, battement du planificateur (alerte si > 3 min), FCM, disque. Le battement exige la tâche `schedule:run` chaque minute (voir README) |
| **Hors ligne** | App > Réglages > **Utiliser hors ligne** | **Télécharger Abidjan**, couper le Wi-Fi/données de l'émulateur (`adb shell svc wifi disable; adb shell svc data disable`), ouvrir Hôpitaux, Pharmacies de garde, recherche, une fiche | Bandeau « Hors ligne · données du jj/mm/aaaa », listes, filtres, recherche (mêmes synonymes), fiches et gardes servis localement. Mettre à jour (rien à retélécharger si inchangé) / supprimer. **Les fonds de carte ne sont pas enregistrés.** Remettre le réseau ensuite |
| **Avis et notes** | App : fiche d'une pharmacie/clinique > **Donner mon avis** ; Espace Utilisateur > **Mes avis** ; Back-office > **Avis** | Noter 4 étoiles + commentaire ; dans le back-office, **Valider** ou **Rejeter** (motif) ; signaler un avis (menu ⋮) | L'avis reste « en vérification » jusqu'à validation ; moyenne et derniers avis publics après validation ; auteur « Prénom I. ». Texte avec numéro, insulte ou « Dr Nom + accusation » : surligné pour le modérateur. Aucun avis sur centres de secours / FGA |
| **Rapports d'erreurs** | App > Réglages > **Confidentialité** | Activer l'interrupteur, relancer l'application | Désactivé par défaut ; choix conservé. Les rapports n'apparaissent dans Firebase qu'après activation de Crashlytics dans la console (projet bravido-6a8dc) |
| **Abonnement famille (test)** | App : Espace Utilisateur > **Abonnement famille** ; Back-office > **Abonnements** (admin) | **Souscrire** > Paiement simulé > « Simuler un succès » | Abonnement actif 30 jours (prix provisoire 1 000 FCFA, « à confirmer »), membres invitables par numéro (4 profils au total). Orange Money / MTN MoMo / Wave : « bientôt disponible ». **Les alertes restent gratuites et identiques pour tous** |

---

## 3. En cas de problème

| Problème | Que faire |
|---|---|
| L'application affiche « Pas de connexion » ou des listes vides | Vérifiez que l'icône WAMP est verte, puis relancez `LANCER-BRAVIDO.bat` |
| Le téléphone virtuel est figé / écran noir | Fermez la fenêtre de l'émulateur, relancez `LANCER-BRAVIDO.bat` |
| « Code déjà envoyé » / refus d'envoyer le code | Attendez 1 minute avant de redemander un code pour le même numéro |
| Le back-office refuse le mot de passe | Vérifiez majuscules et le `!` : `ChangezMoi!2026` |
| La fenêtre noire affiche **ECHEC** en rouge | Lisez le message, corrigez (souvent : lancer WAMP), relancez. Sinon, faites une capture d'écran de la fenêtre et envoyez-la à l'équipe technique |
