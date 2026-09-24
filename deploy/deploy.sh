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

# Server config that lives outside the repo (Apache vhost + PHP hardening) is
# installed from the repo on every deploy so security settings can't drift.
# If the new Apache config doesn't pass configtest, restore the old one and
# stop *before* reloading, so a bad edit can never take the site down.
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
VHOST="/etc/apache2/sites-available/officecardprint.conf"
PHPINI="/etc/php/${PHP_VER}/apache2/conf.d/99-officecardprint.ini"
cp -p "$VHOST" "$VHOST.prev" 2>/dev/null || true
[ -f "$PHPINI" ] && cp -p "$PHPINI" "$PHPINI.prev" || true
install -m 644 "$APP_DIR/deploy/apache-officecardprint.conf" "$VHOST"
install -m 644 "$APP_DIR/deploy/php-hardening.ini" "$PHPINI"
if ! apache2ctl configtest 2>/dev/null; then
  echo "!! New Apache/PHP config failed configtest — restoring the previous one, not reloading." >&2
  [ -f "$VHOST.prev" ] && mv -f "$VHOST.prev" "$VHOST"
  if [ -f "$PHPINI.prev" ]; then mv -f "$PHPINI.prev" "$PHPINI"; else rm -f "$PHPINI"; fi
  exit 1
fi
rm -f "$VHOST.prev" "$PHPINI.prev"

# Re-apply schema in case it changed; CREATE TABLE IF NOT EXISTS is a no-op otherwise.
mysql -u root "$(grep '^DB_DATABASE=' .env | cut -d= -f2)" < "$APP_DIR/deploy/schema.sql"

# Fold pre-existing per-print rows into one card per RC number + audit log.
# Idempotent — a no-op once everything has been migrated.
php "$APP_DIR/deploy/migrate.php"

systemctl reload apache2
echo "Deployed $(git rev-parse --short HEAD)"
