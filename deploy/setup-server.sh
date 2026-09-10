#!/usr/bin/env bash
# Office Card Print — first-time Ubuntu server setup.
# Run once, as root, on a fresh Ubuntu 22.04+ box: sudo ./setup-server.sh
#
# What it does:
#   1. Installs Apache, PHP (+ pdo_mysql/ldap extensions), MySQL, git
#   2. Creates the MySQL database + app user, and the `users` table
#   3. Clones the app from GitHub (private repo — needs a deploy key, see below)
#   4. Writes .env with generated DB credentials (you fill in the LDAP_* values)
#   5. Installs the Apache vhost from deploy/apache-officecardprint.conf
#
# Before running, add a deploy key to the GitHub repo so this server can
# clone it (repo Settings > Deploy keys, read-only access is enough):
#   ssh-keygen -t ed25519 -f /root/.ssh/officecardprint_deploy -N ""
#   cat /root/.ssh/officecardprint_deploy.pub
# If the key doesn't exist yet, this script generates it and stops so you
# can add it to GitHub, then re-run.

set -euo pipefail

REPO_URL="git@github.com:shazeensr/officecardprint.git"
APP_DIR="/var/www/officecardprint"
DB_NAME="officecardprint"
DB_USER="officecardprint"
DB_PASS="$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 32)"
SITE_NAME="officecardprint"
DEPLOY_KEY="/root/.ssh/officecardprint_deploy"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

echo "==> Installing packages"
apt-get update
apt-get install -y apache2 mysql-server git curl \
  php php-mysql php-ldap php-mbstring php-xml php-cli php-curl libapache2-mod-php

if ! php -r 'exit(version_compare(PHP_VERSION, "8.0.0", "<") ? 1 : 0);'; then
  echo "!! PHP $(php -r 'echo PHP_VERSION;') found, but this app needs PHP 8.0+."
  echo "   Add a newer PHP PPA and re-run, e.g.:"
  echo "     add-apt-repository ppa:ondrej/php && apt-get update"
  exit 1
fi

echo "==> Checking deploy key"
if [[ ! -f "$DEPLOY_KEY" ]]; then
  ssh-keygen -t ed25519 -f "$DEPLOY_KEY" -N "" -q
  echo
  echo "No deploy key existed, so one was generated. Add this public key to"
  echo "GitHub (repo > Settings > Deploy keys) then re-run this script:"
  echo
  cat "${DEPLOY_KEY}.pub"
  exit 1
fi
export GIT_SSH_COMMAND="ssh -i ${DEPLOY_KEY} -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"

echo "==> Setting up MySQL database"
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> Fetching app code"
if [[ -d "$APP_DIR/.git" ]]; then
  git -C "$APP_DIR" pull
else
  git clone "$REPO_URL" "$APP_DIR"
fi

echo "==> Loading schema"
mysql -u root "$DB_NAME" < "$APP_DIR/deploy/schema.sql"

echo "==> Writing .env"
if [[ ! -f "$APP_DIR/.env" ]]; then
  cat > "$APP_DIR/.env" <<ENV
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

LDAP_HOST=
LDAP_PORT=389
LDAP_USE_TLS=true
LDAP_BASE_DN="DC=immigration,DC=local"
LDAP_BIND_USER=""
LDAP_BIND_PASSWORD=
LDAP_GROUP_DN="CN=GRS_Software,CN=ForeignSecurityPrincipals,DC=immigration,DC=local"
ENV
  echo "   Wrote $APP_DIR/.env — fill in the LDAP_* values before going live."
else
  echo "   $APP_DIR/.env already exists, leaving it alone."
fi

echo "==> Setting permissions"
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
find "$APP_DIR" -type f -exec chmod 640 {} \;

echo "==> Configuring Apache"
cp "$APP_DIR/deploy/apache-officecardprint.conf" "/etc/apache2/sites-available/${SITE_NAME}.conf"
a2ensite "${SITE_NAME}.conf" >/dev/null
a2dissite 000-default.conf >/dev/null 2>&1 || true
apache2ctl configtest
systemctl reload apache2

echo
echo "==> Done."
echo "DB user '${DB_USER}' password: ${DB_PASS}  (already saved in $APP_DIR/.env)"
echo "Next: edit $APP_DIR/.env with your LDAP settings, then:"
echo "  systemctl reload apache2"
