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
CERT_HOSTNAME="ocp.immigration.local"
CERT_IP="$(hostname -I | awk '{print $1}')"

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

echo "==> Generating self-signed TLS certificate"
# No public domain to get a Let's Encrypt cert for (internal IP only), so
# this is self-signed — browsers will show a one-time trust warning, but
# traffic is still fully encrypted. Covers both the internal hostname and
# the server's own IP via SAN. Swap in a CA-issued cert later without
# touching the vhost — just replace these two files.
if [[ ! -f /etc/ssl/certs/officecardprint.crt ]]; then
  mkdir -p /etc/ssl/officecardprint
  cat > /etc/ssl/officecardprint/san.cnf <<EOF
[req]
distinguished_name = dn
x509_extensions = v3_req
prompt = no
[dn]
CN = ${CERT_HOSTNAME}
O = Office Card Print
[v3_req]
subjectAltName = @alt_names
[alt_names]
DNS.1 = ${CERT_HOSTNAME}
IP.1 = ${CERT_IP}
EOF
  openssl req -x509 -nodes -newkey rsa:2048 \
    -keyout /etc/ssl/private/officecardprint.key \
    -out /etc/ssl/certs/officecardprint.crt \
    -days 730 \
    -config /etc/ssl/officecardprint/san.cnf
  chmod 600 /etc/ssl/private/officecardprint.key
else
  echo "   Certificate already exists, leaving it alone."
fi

echo "==> Configuring Apache"
a2enmod headers rewrite ssl >/dev/null
cp "$APP_DIR/deploy/apache-officecardprint.conf" "/etc/apache2/sites-available/${SITE_NAME}.conf"
a2ensite "${SITE_NAME}.conf" >/dev/null
a2dissite 000-default.conf >/dev/null 2>&1 || true

# Stop leaking the exact Apache/OS version in the Server header and on
# error pages — reduces fingerprinting for known-CVE targeting.
sed -i \
  -e 's/^ServerTokens .*/ServerTokens Prod/' \
  -e 's/^ServerSignature .*/ServerSignature Off/' \
  /etc/apache2/conf-available/security.conf

# Reject client-supplied session IDs PHP never generated itself —
# defense-in-depth against session fixation.
PHP_INI="/etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/apache2/php.ini"
if [[ -f "$PHP_INI" ]]; then
  sed -i 's/^session\.use_strict_mode = 0/session.use_strict_mode = 1/' "$PHP_INI"
fi

apache2ctl configtest
systemctl reload apache2

echo
echo "==> Done."
echo "DB user '${DB_USER}' password: ${DB_PASS}  (already saved in $APP_DIR/.env)"
echo "Site is now served over HTTPS (self-signed cert) at https://${CERT_HOSTNAME}/"
echo "or https://${CERT_IP}/ — browsers will warn once since it's not CA-signed;"
echo "plain http:// now redirects here automatically."
echo "Next: edit $APP_DIR/.env with your LDAP settings, then:"
echo "  systemctl reload apache2"
