# BRAVIDO : premier environnement de test sur un serveur Linux (VPS)

Guide pas à pas, pour une personne qui n'a jamais administré de serveur. Comptez 2 à 3 heures la première fois.
Rien ici n'est exécuté automatiquement : vous copiez les commandes une par une dans un terminal SSH.

> Vocabulaire : **VPS** = un petit serveur loué chez un hébergeur. **SSH** = connexion en ligne de commande au serveur.
> `<domaine>` = votre nom de domaine (ex. `bravido.ci`). Remplacez toujours ce qui est entre `< >`.

Fichiers de ce dossier : `nginx-bravido.conf`, `supervisor-bravido-worker.conf`, `cron-bravido.txt`,
`env.production.example`, `deploy.sh`, `backup-bravido.sh`.

---

## 0. Ce qu'il faut décider avant de commencer

| Décision | Recommandation pour un test |
|---|---|
| Hébergeur | Un VPS **Ubuntu 24.04 LTS**, 2 vCPU / 2 Go de RAM / 40 Go de disque (≈ 5 à 12 €/mois : Hetzner, OVH, Scaleway, DigitalOcean, Contabo…). Pour des utilisateurs en Côte d'Ivoire, choisissez un datacenter en Europe de l'Ouest (France) pour la latence. |
| Nom de domaine | Un domaine à vous (ex. `bravido.ci`, ou un `.com` pour le test). Sans domaine, pas de HTTPS, donc pas d'application mobile en version « release » (Android bloque le HTTP non chiffré). |
| Dépôt git | Un dépôt **privé** (GitHub/GitLab) où vous poussez le code **vous-même** depuis votre PC. Le serveur le clone avec une « deploy key » en lecture seule (étape 7). |
| SMS réels ? | Pour le premier test : non (`SMS_DRIVER=log`, aucun coût). Voir étape 16. |
| Paiement réel ? | Non : laissez `PAIEMENT_SANDBOX=true` (paiement simulé). |

---

## 1. Louer le serveur et noter ses informations

1. Créez le VPS (image **Ubuntu 24.04**). Ajoutez votre **clé SSH publique** si l'hébergeur le propose (plus sûr qu'un mot de passe).
2. Notez l'**adresse IP publique** (ex. `203.0.113.10`).
3. Depuis votre PC (PowerShell) : `ssh root@203.0.113.10`.

## 2. DNS : faire pointer le domaine vers le serveur

Chez votre registrar (là où vous avez acheté le domaine), créez un enregistrement **A** :

| Type | Nom | Valeur |
|---|---|---|
| A | `api` | IP du serveur |

Le back-office (`/admin`) est servi sur le même hôte : `https://api.<domaine>/admin`.
Vérifiez la propagation (quelques minutes à quelques heures) : `nslookup api.<domaine>` doit renvoyer votre IP.

## 3. Sécuriser l'accès (utilisateur, pare-feu, fail2ban)

```bash
apt update && apt -y upgrade
adduser deploy                     # choisissez un mot de passe fort
usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy   # copie votre clé SSH

# Pare-feu : seulement SSH, HTTP, HTTPS
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status

# Bloque automatiquement les IP qui essaient de deviner le mot de passe SSH
apt -y install fail2ban
systemctl enable --now fail2ban
```

Ouvrez un **second** terminal et vérifiez que `ssh deploy@<IP>` fonctionne avant de fermer la session root.
(Option conseillée ensuite : désactiver la connexion root et par mot de passe dans `/etc/ssh/sshd_config` : `PermitRootLogin no`, `PasswordAuthentication no`, puis `systemctl reload ssh`.)

Mises à jour de sécurité automatiques : `apt -y install unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades`.

## 4. Installer les logiciels

Connecté en `deploy` (les commandes `sudo` demandent votre mot de passe) :

```bash
sudo apt -y install nginx mysql-server git unzip curl supervisor certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-gd php8.3-intl php8.3-bcmath tesseract-ocr tesseract-ocr-fra

# Composer (gestionnaire de dépendances PHP)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version && php -v
```

`tesseract-ocr` (+ français) sert uniquement à lire les programmes de garde au format **image** dans le back-office.

Réglages PHP utiles : `sudo nano /etc/php/8.3/fpm/php.ini` puis `upload_max_filesize = 8M`, `post_max_size = 10M`, `expose_php = Off`, puis `sudo systemctl reload php8.3-fpm`.

## 5. Créer la base de données MySQL

```bash
sudo mysql_secure_installation       # répondez Y partout ; définissez un mot de passe root MySQL
sudo mysql
```

Dans l'invite `mysql>` (choisissez un mot de passe **long et unique** à la place de `<mot-de-passe-bd>`) :

```sql
CREATE DATABASE bravido CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bravido'@'localhost' IDENTIFIED BY '<mot-de-passe-bd>';
GRANT ALL PRIVILEGES ON bravido.* TO 'bravido'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

MySQL n'écoute que sur `127.0.0.1` par défaut : ne l'ouvrez **jamais** sur Internet (le pare-feu de l'étape 3 le bloque déjà).

## 6. Préparer le dépôt git privé (depuis votre PC)

Le dossier du projet contient déjà un dépôt git local (commits faits, rien n'a été poussé). Sur votre PC :

1. Créez un dépôt **privé** vide sur GitHub (ou GitLab).
2. `git remote add origin <url-du-depot>` puis `git push -u origin main` (c'est vous qui décidez de pousser).
3. Vérifiez sur le site que `backend/.env`, `backend/vendor`, `docs/donnees` et les clés Firebase **ne sont pas** présents (le `.gitignore` les exclut).

## 7. Récupérer le code sur le serveur

Sur le serveur, créez une clé de déploiement en lecture seule :

```bash
ssh-keygen -t ed25519 -C "bravido-deploy" -f ~/.ssh/id_bravido -N ""
cat ~/.ssh/id_bravido.pub
```

Copiez cette ligne dans GitHub : dépôt → *Settings* → *Deploy keys* → *Add* (laissez « Allow write access » **décoché**).
Puis :

```bash
printf 'Host github.com\n  IdentityFile ~/.ssh/id_bravido\n' >> ~/.ssh/config && chmod 600 ~/.ssh/config
sudo mkdir -p /var/www/bravido && sudo chown deploy:www-data /var/www/bravido
git clone git@github.com:<compte>/<depot>.git /var/www/bravido
cd /var/www/bravido/backend
composer install --no-dev -o
```

## 8. Configuration `.env`

```bash
cp /var/www/bravido/deploy/env.production.example /var/www/bravido/backend/.env
nano /var/www/bravido/backend/.env      # remplacez chaque <...>
php artisan key:generate --force
```

Points obligatoires :

- `APP_ENV=production` et `APP_DEBUG=false` (sinon les erreurs affichent des détails sensibles).
- `APP_URL=https://api.<domaine>` (utilisé dans les liens des SMS de suivi de trajet et d'alerte).
- `DB_PASSWORD` = celui de l'étape 5.
- `ADMIN_PASSWORD` : **changez-le** (voir étape 17). Le mot de passe d'exemple `ChangezMoi!2026` est public dans le dépôt.
- **Supprimez** la ligne `OTP_CODE_TEST` (code OTP fixe de développement ; ignoré hors `APP_ENV=local` mais à ne pas laisser).
- `PAIEMENT_SANDBOX=true` pour un test ; **`false` seulement** quand de vrais comptes marchands (Orange Money, MTN MoMo, Wave) sont configurés.
- `QUEUE_CONNECTION=database` : jamais `sync` (les alertes SOS doivent partir en arrière-plan).
- **Ne perdez pas `APP_KEY`** : elle chiffre les fiches médicales. Sauvegardez-la dans un gestionnaire de mots de passe.

```bash
chmod 640 /var/www/bravido/backend/.env
```

## 9. Base de données : tables, comptes de base, données

```bash
cd /var/www/bravido/backend
php artisan migrate --force
php artisan db:seed --force          # référentiel (catégories, villes, numéros d'urgence, assureurs) + compte administrateur ; une seule fois, jamais DemoSeeder
php artisan storage:link
```

### Importer l'annuaire GUIDSANTE

Les données (`docs/donnees/`) **ne sont pas dans git** (licence incertaine, taille). Depuis votre PC, envoyez uniquement les deux fichiers nécessaires :

```powershell
scp C:\wamp64\www\bravido\docs\donnees\etablissements.csv deploy@<IP>:/tmp/
scp C:\wamp64\www\bravido\docs\donnees\localites.json     deploy@<IP>:/tmp/
```

Sur le serveur :

```bash
cd /var/www/bravido/backend
# 1) Simulation : n'écrit rien, affiche le bilan
php artisan bravido:importer-guidsante /tmp/etablissements.csv /tmp/localites.json --dry-run
# 2) Import réel (relançable : une ligne déjà importée n'est ni recréée ni écrasée)
php artisan bravido:importer-guidsante /tmp/etablissements.csv /tmp/localites.json
# 3) Optionnel : retirer les établissements fictifs « DÉMO » du jeu de démonstration
php artisan bravido:importer-guidsante /tmp/etablissements.csv /tmp/localites.json --supprimer-demo
rm /tmp/etablissements.csv /tmp/localites.json
```

Les établissements importés n'ont pas de GPS précis (position = centre de la commune) ; les lignes douteuses arrivent en **brouillon** à valider dans le back-office.

## 10. Droits sur les fichiers

```bash
cd /var/www/bravido/backend
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
sudo chown deploy:www-data .env && sudo chmod 640 .env
```

`www-data` (le compte de nginx/PHP) doit pouvoir écrire dans `storage/` et `bootstrap/cache/` uniquement.

## 11. Caches de production

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

(À refaire après chaque modification du `.env` : `php artisan config:cache`. `deploy.sh` le fait pour vous.)

## 12. nginx + HTTPS (certbot)

```bash
sudo cp /var/www/bravido/deploy/nginx-bravido.conf /etc/nginx/sites-available/bravido
sudo nano /etc/nginx/sites-available/bravido        # remplacez api.exemple.ci par api.<domaine>
sudo ln -s /etc/nginx/sites-available/bravido /etc/nginx/sites-enabled/bravido
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# Certificat HTTPS gratuit (Let's Encrypt) : le DNS de l'étape 2 doit déjà pointer vers le serveur
sudo certbot --nginx -d api.<domaine> --redirect -m <votre-email> --agree-tos -n
sudo certbot renew --dry-run        # le renouvellement automatique est installé par certbot
```

Ensuite, dans `nginx-bravido.conf`, vous pouvez décommenter l'en-tête `Strict-Transport-Security` (HSTS) une fois que tout marche en HTTPS.

Test : `https://api.<domaine>/admin` doit afficher la page de connexion, et `https://api.<domaine>/api/v1/sante` répondre en JSON.

**Proxys de confiance (TRUSTED PROXIES).** Avec cette installation, nginx est directement exposé : rien à faire. Si vous placez plus tard un proxy devant (Cloudflare, load balancer…), Laravel verra l'IP du proxy au lieu de celle de l'utilisateur et pourrait générer des liens `http://` : il faut alors déclarer le proxy dans `backend/bootstrap/app.php` (`$middleware->trustProxies(at: '<IP-du-proxy>')` ; `'*'` seulement si le serveur n'est joignable QUE via ce proxy) et le limiteur de débit (connexion admin, OTP) utilisera la vraie IP.

## 13. File d'attente (supervisor) et tâches planifiées (cron)

Le **worker** envoie les notifications push/SMS des alertes. Sans lui, **aucune alerte SOS ne part**.

```bash
sudo cp /var/www/bravido/deploy/supervisor-bravido-worker.conf /etc/supervisor/conf.d/bravido-worker.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status            # bravido-worker:bravido-worker_00 et _01 doivent être RUNNING
```

Le **planificateur** (clôture des alertes, vérification des trajets, expiration des abonnements, purge RGPD à 02h30) :

```bash
sudo crontab -u www-data -e          # ajoutez la ligne n°1 de cron-bravido.txt
```

Rotation des journaux (`/etc/logrotate.d/bravido`) :

```bash
sudo tee /etc/logrotate.d/bravido >/dev/null <<'EOF'
/var/www/bravido/backend/storage/logs/*.log /var/log/bravido-worker.log /var/log/bravido-backup.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
EOF
```

## 14. Notifications push : identifiants Firebase

Le fichier JSON du **compte de service** Firebase est un secret : il ne passe jamais par git. Depuis votre PC :

```powershell
scp C:\chemin\vers\firebase-service-account.json deploy@<IP>:/tmp/
```

Sur le serveur :

```bash
sudo mv /tmp/firebase-service-account.json /var/www/bravido/backend/storage/app/private/
sudo chown www-data:www-data /var/www/bravido/backend/storage/app/private/firebase-service-account.json
sudo chmod 600 /var/www/bravido/backend/storage/app/private/firebase-service-account.json
```

Dans `.env` : `FCM_PROJECT_ID=<id>` et `FCM_CREDENTIALS=/var/www/bravido/backend/storage/app/private/firebase-service-account.json`, puis `php artisan config:cache`.
Côté mobile, `google-services.json` (Android) et `GoogleService-Info.plist` (iOS) restent sur votre PC (voir `README-TEST-TELEPHONE.md`).

## 15. Sauvegardes quotidiennes de la base (rotation 14 jours)

```bash
sudo cp /var/www/bravido/deploy/backup-bravido.sh /usr/local/bin/backup-bravido.sh
sudo chmod 750 /usr/local/bin/backup-bravido.sh
sudo mkdir -p /var/backups/bravido && sudo chmod 700 /var/backups/bravido
sudo /usr/local/bin/backup-bravido.sh          # test manuel : doit afficher « OK ... »
sudo crontab -e                                 # ajoutez la ligne n°2 de cron-bravido.txt (03h15)
```

**Test de restauration** (à faire au moins une fois, sur une base de test, pas sur la vraie) :
`gunzip -c /var/backups/bravido/bravido-AAAAMMJJ-HHMMSS.sql.gz | mysql -u root -p bravido_restauration`.
Une sauvegarde jamais restaurée n'est pas une sauvegarde. Copiez aussi régulièrement `/var/backups/bravido` **hors du serveur** (`scp`/`rsync` vers votre PC ou un stockage objet) et gardez une copie de `APP_KEY`.

## 16. SMS : choisir le mode

- **`SMS_DRIVER=log`** (défaut du test) : aucun SMS n'est envoyé ; le contenu est écrit dans `storage/logs/`. Suffisant pour tester le parcours.
- **`SMS_DRIVER=http`** : l'application appelle votre agrégateur SMS (Orange CI, MTN, Infobip, Twilio…). Renseignez `SMS_URL` (point d'accès d'envoi de l'agrégateur), `SMS_TOKEN` (clé d'API) et `SMS_SENDER` (nom d'expéditeur, à faire valider chez l'opérateur ; en CI un nom alphanumérique est généralement soumis à enregistrement). Puis `php artisan config:cache` et testez avec **votre propre numéro**. Attention au coût : chaque alerte et chaque code OTP envoient des SMS.

## 17. Mot de passe administrateur et sécurité de production

Après la première connexion à `https://api.<domaine>/admin`, changez le mot de passe. Depuis le serveur, avec tinker :

```bash
cd /var/www/bravido/backend
php artisan tinker
```

```php
$u = App\Models\User::where('email', 'admin@bravido.ci')->first();   // adaptez à votre ADMIN_EMAIL
$u->password = 'VotreNouveauMotDePasseLongEtUnique';                  // le modèle le chiffre (hash) automatiquement
$u->save();
exit
```

Si la commande ne vérifie pas le hash, utilisez `$u->password = Hash::make('...')`. Évitez de laisser le mot de passe dans l'historique du shell : tapez `history -c` ensuite.

Liste de contrôle « production » :

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `ADMIN_PASSWORD` changé, mot de passe admin changé dans l'application
- [ ] `OTP_CODE_TEST` supprimé du `.env`
- [ ] `PAIEMENT_SANDBOX=false` **uniquement** si de vrais comptes marchands sont actifs (sinon `true` pour tester)
- [ ] HTTPS actif (certbot), `SESSION_SECURE_COOKIE=true`
- [ ] Pare-feu `ufw` : 22, 80, 443 seulement ; `fail2ban` actif
- [ ] Worker supervisor `RUNNING`, cron installé, sauvegarde testée
- [ ] Fichier Firebase en `chmod 600`, absent de git

## 18. Vérifier que tout fonctionne (health check)

L'adresse **`https://api.<domaine>/api/v1/sante`** est publique et minimale : elle renvoie du JSON avec des booléens (base de données, file d'attente, etc.) et le code HTTP **200** si la base répond, **503** sinon.

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://api.<domaine>/api/v1/sante     # 200 attendu
```

Supervision gratuite : créez un moniteur « HTTP(s) » chez **UptimeRobot** ou **Better Stack** (intervalle 5 min) sur cette URL avec alerte e-mail/SMS si le code n'est pas 200. Le tableau de bord du back-office affiche aussi l'état détaillé (file d'attente, travaux en échec, dernier passage du planificateur) : consultez-le après chaque mise à jour.

## 19. Mettre à jour (déploiement)

Sur votre PC : `git push`. Sur le serveur, en tant que `deploy` :

```bash
cd /var/www/bravido && bash deploy/deploy.sh            # branche main par défaut
```

Le script : active la maintenance → `git pull --ff-only` → `composer install --no-dev -o` → `migrate --force` → caches → `queue:restart` → remet en ligne → teste `/api/v1/sante`. Il s'arrête à la première erreur (`set -euo pipefail`) et remet le site en ligne si une étape échoue.
(Autorisez `sudo -n systemctl reload php8.3-fpm` sans mot de passe via `sudo visudo -f /etc/sudoers.d/deploy` : `deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm, /usr/bin/chown -R deploy\:www-data storage bootstrap/cache` ; sinon lancez ces commandes à la main, ce n'est pas bloquant.)

## 20. Revenir en arrière (rollback)

1. Version précédente : `cat /var/www/bravido/.derniere-version` (écrite par `deploy.sh` avant chaque mise à jour).
2. ```bash
   cd /var/www/bravido
   php backend/artisan down
   git checkout <identifiant-de-version>
   cd backend && composer install --no-dev -o
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   php artisan queue:restart
   php artisan up
   ```
3. **Les migrations ne reviennent pas toutes seules.** Si la mise à jour contenait une migration destructrice, restaurez la sauvegarde de la nuit (`/var/backups/bravido`) ; avant un déploiement risqué, lancez `sudo /usr/local/bin/backup-bravido.sh` à la main.
4. Revenez ensuite à la branche : `git checkout main` (et corrigez avant de redéployer).

## 21. Dépannage rapide

| Symptôme | Piste |
|---|---|
| Page blanche / erreur 500 | `tail -n 50 /var/www/bravido/backend/storage/logs/laravel-*.log` ; droits de `storage/` (étape 10) |
| 502 Bad Gateway | `sudo systemctl status php8.3-fpm` ; le chemin du socket dans `nginx-bravido.conf` |
| Aucune notification d'alerte | `sudo supervisorctl status` ; `php artisan queue:failed` ; identifiants Firebase |
| Modification du `.env` sans effet | `php artisan config:cache` |
| Upload d'image refusé | `client_max_body_size` (nginx) et `upload_max_filesize` (php.ini) |
| Certbot échoue | le DNS ne pointe pas encore vers le serveur, ou le port 80 est fermé |
