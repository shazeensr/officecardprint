#!/usr/bin/env bash
# Office Card Print — pull latest and redeploy. Run as root: sudo ./deploy.sh
set -euo pipefail

APP_DIR="/var/www/officecardprint"
DEPLOY_KEY="/root/.ssh/officecardprint_deploy"
export GIT_SSH_COMMAND="ssh -i ${DEPLOY_KEY} -o IdentitiesOnly=yes"

cd "$APP_DIR"
# The permission lockdown below strips the executable bit off this script
# (and setup-server.sh) every run, which makes the *next* git pull fail on
# a mode-only conflict. Discard that before pulling.
git checkout -- deploy/*.sh 2>/dev/null || true
git pull

chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
find "$APP_DIR" -type f -exec chmod 640 {} \;
chmod 750 deploy/*.sh

# Re-apply schema in case it changed; CREATE TABLE IF NOT EXISTS is a no-op otherwise.
mysql -u root "$(grep '^DB_DATABASE=' .env | cut -d= -f2)" < "$APP_DIR/deploy/schema.sql"

systemctl reload apache2
echo "Deployed $(git rev-parse --short HEAD)"
