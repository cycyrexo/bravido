#!/usr/bin/env bash
# Mise a jour de BRAVIDO sur le serveur.  A lancer avec l'utilisateur « deploy » (proprietaire du depot) :
#   cd /var/www/bravido && bash deploy/deploy.sh [branche]
# Rollback : voir README-DEPLOIEMENT.md (section « Revenir en arriere »).
set -euo pipefail
umask 002   # fichiers crees (logs, caches) lisibles/inscriptibles par le groupe www-data

APP_DIR="${APP_DIR:-/var/www/bravido}"
BACKEND="$APP_DIR/backend"
BRANCHE="${1:-main}"
PHP="${PHP_BIN:-php}"

cd "$APP_DIR"

# Si quoi que ce soit echoue apres la mise en maintenance, on remet le site en ligne
# (le code deja tire reste en place : corrigez puis relancez, ou faites un rollback).
en_ligne() { "$PHP" "$BACKEND/artisan" up || true; }
trap 'echo "ECHEC du deploiement (ligne $LINENO). Site remis en ligne." >&2; en_ligne' ERR

echo "==> Version actuelle : $(git rev-parse --short HEAD)"
echo "$(git rev-parse HEAD)" > "$APP_DIR/.derniere-version"   # sert au rollback

echo "==> Mode maintenance"
"$PHP" "$BACKEND/artisan" down --retry=30 --refresh=15 || true

echo "==> Recuperation du code ($BRANCHE)"
git fetch --prune origin
git checkout "$BRANCHE"
git pull --ff-only origin "$BRANCHE"

echo "==> Dependances PHP"
cd "$BACKEND"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Migrations"
"$PHP" artisan migrate --force

echo "==> Liens et caches"
"$PHP" artisan storage:link 2>/dev/null || true
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache 2>/dev/null || true

echo "==> Droits sur storage et bootstrap/cache"
sudo -n chown -R "$(id -un)":www-data storage bootstrap/cache 2>/dev/null || chgrp -R www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
find storage bootstrap/cache -type d -exec chmod g+s {} + 2>/dev/null || true
chmod 600 storage/app/private/*.json 2>/dev/null || true

echo "==> Redemarrage des workers de file d'attente"
"$PHP" artisan queue:restart

echo "==> OPcache (PHP-FPM) : rechargement"
sudo -n systemctl reload php8.3-fpm 2>/dev/null || echo "   (rechargement php-fpm non effectue : lancez 'sudo systemctl reload php8.3-fpm')"

trap - ERR
echo "==> Remise en ligne"
"$PHP" artisan up

echo "==> Verification"
sleep 2
URL="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '\"')"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$URL/api/v1/sante" || echo 000)"
echo "    $URL/api/v1/sante -> HTTP $CODE (200 attendu)"
[ "$CODE" = "200" ] || { echo "ATTENTION : la verification a echoue. Consultez storage/logs/ et envisagez un rollback." >&2; exit 1; }

echo "OK : version $(git rev-parse --short HEAD) en ligne."
