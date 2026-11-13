#!/usr/bin/env bash
# Sauvegarde quotidienne de la base BRAVIDO (mysqldump compresse) avec rotation sur 14 jours.
# Installation :
#   sudo cp deploy/backup-bravido.sh /usr/local/bin/backup-bravido.sh && sudo chmod 750 /usr/local/bin/backup-bravido.sh
#   sudo mkdir -p /var/backups/bravido && sudo chmod 700 /var/backups/bravido
#   (cron : voir cron-bravido.txt ; lance par root)
# Les identifiants sont lus dans le .env (jamais ecrits sur la ligne de commande -> invisibles dans `ps`).
# IMPORTANT : copiez regulierement ces sauvegardes AILLEURS (autre serveur, stockage objet) :
# une sauvegarde sur le meme disque ne protege pas d'une panne du serveur.
set -euo pipefail
umask 077

APP_ENV_FILE="${APP_ENV_FILE:-/var/www/bravido/backend/.env}"
DEST="${DEST:-/var/backups/bravido}"
JOURS="${JOURS:-14}"

lire() { grep -E "^$1=" "$APP_ENV_FILE" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

DB_NAME="$(lire DB_DATABASE)"
DB_USER="$(lire DB_USERNAME)"
DB_PASS="$(lire DB_PASSWORD)"
DB_HOST="$(lire DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"

mkdir -p "$DEST"
FICHIER="$DEST/bravido-$(date +%Y%m%d-%H%M%S).sql.gz"

# Fichier d'options temporaire (mot de passe hors ligne de commande), supprime a la sortie
OPTS="$(mktemp)"; trap 'rm -f "$OPTS"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$DB_USER" "$DB_PASS" "$DB_HOST" > "$OPTS"

mysqldump --defaults-extra-file="$OPTS" --single-transaction --quick --routines --triggers \
  --no-tablespaces "$DB_NAME" | gzip -9 > "$FICHIER"

# Verification : le fichier doit etre un gzip valide et non vide
gzip -t "$FICHIER"
[ -s "$FICHIER" ] || { echo "Sauvegarde vide : $FICHIER" >&2; exit 1; }

# Rotation : suppression des sauvegardes de plus de $JOURS jours
find "$DEST" -name 'bravido-*.sql.gz' -type f -mtime +"$JOURS" -delete

echo "$(date '+%F %T') OK $FICHIER ($(du -h "$FICHIER" | cut -f1))"

# Photos televersees (storage/app/public) : sauvegarde hebdomadaire le dimanche
if [ "$(date +%u)" = "7" ]; then
  tar -czf "$DEST/bravido-fichiers-$(date +%Y%m%d).tar.gz" -C /var/www/bravido/backend/storage/app public 2>/dev/null || true
  find "$DEST" -name 'bravido-fichiers-*.tar.gz' -type f -mtime +"$JOURS" -delete
fi
