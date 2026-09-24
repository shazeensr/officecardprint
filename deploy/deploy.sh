#!/usr/bin/env bash
# Office Card Print — pull latest and redeploy. Run as root: sudo ./deploy.sh
set -euo pipefail

APP_DIR="/var/www/officecardprint"
DEPLOY_KEY="/root/.ssh/officecardprint_deploy"
export GIT_SSH_COMMAND="ssh -i ${DEPLOY_KEY} -o IdentitiesOnly=yes"

cd "$APP_DIR"
# Modes are managed by the lockdown below, not git — ignore mode-only diffs so
# a pull can never fail on them.
git config core.fileMode false
git pull

chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
# deploy/*.sh are run directly (sudo .../deploy.sh), so they must never lose
# their executable bit — exclude them from the 640 lockdown.
find "$APP_DIR" -type f ! -path "$APP_DIR/deploy/*.sh" -exec chmod 640 {} \;
chmod 750 "$APP_DIR"/deploy/*.sh

# Re-apply schema in case it changed; CREATE TABLE IF NOT EXISTS is a no-op otherwise.
mysql -u root "$(grep '^DB_DATABASE=' .env | cut -d= -f2)" < "$APP_DIR/deploy/schema.sql"

# Fold pre-existing per-print rows into one card per RC number + audit log.
# Idempotent — a no-op once everything has been migrated.
php "$APP_DIR/deploy/migrate.php"

systemctl reload apache2
echo "Deployed $(git rev-parse --short HEAD)"
