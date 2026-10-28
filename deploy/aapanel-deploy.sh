#!/usr/bin/env bash
# =============================================================================
#  pkpay.site: one-command deployment for aaPanel (first install AND updates)
#
#    bash <(curl -fsSL https://raw.githubusercontent.com/knsoftic/qr-based-system/main/deploy/aapanel-deploy.sh)
#
#  Before the first run (aaPanel GUI, about a minute):
#    1. Website > Add site > pkpay.site, PHP-82 (or newer)
#    2. pkpay.site > SSL > Let's Encrypt > Apply, then Force HTTPS ON
#
#  It then does everything else: PHP checks/fixes, git + composer, code,
#  database + user, .env, migrations, caches, first admin (random password),
#  /public + URL rewrite, cron job, health check. Running it again updates.
#
#  Optional overrides: DOMAIN, ADMIN_EMAIL, ADMIN_PASSWORD,
#                      MYSQL_ROOT_PASSWORD, DB_NAME, DB_USER
# =============================================================================
set -euo pipefail

DOMAIN="${DOMAIN:-pkpay.site}"
APP_DIR="${APP_DIR:-/www/wwwroot/$DOMAIN}"
REPO="${REPO:-https://github.com/knsoftic/qr-based-system.git}"
DB_NAME="${DB_NAME:-pkpay}"
DB_USER="${DB_USER:-pkpay}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@$DOMAIN}"
CREDS_FILE="/root/$DOMAIN-credentials.txt"
PANEL=/www/server/panel
NGINX_CONF="$PANEL/vhost/nginx/$DOMAIN.conf"
APACHE_CONF="$PANEL/vhost/apache/$DOMAIN.conf"
REWRITE_CONF="$PANEL/vhost/rewrite/$DOMAIN.conf"
export COMPOSER_ALLOW_SUPERUSER=1

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# -----------------------------------------------------------------------------
step "Checks"
[ "$(id -u)" -eq 0 ] || fail "Root user se chalayein (pehle: sudo -i)."
[ -d "$PANEL" ] || fail "aaPanel nahi mila ($PANEL)."

if [ -f "$NGINX_CONF" ]; then
    WEB=nginx; VHOST="$NGINX_CONF"
elif [ -f "$APACHE_CONF" ]; then
    WEB=apache; VHOST="$APACHE_CONF"
else
    fail "Website '$DOMAIN' aaPanel mein nahi bani. Pehle: aaPanel > Website > Add site > $DOMAIN (PHP-82), phir yehi command dobara."
fi
mkdir -p "$APP_DIR"
echo "Website: $DOMAIN ($WEB), folder: $APP_DIR"

# -----------------------------------------------------------------------------
step "PHP"
PHP_VER="$(grep -hoE '(enable-php-|php-cgi-)[0-9]+' "$VHOST" | head -1 | grep -oE '[0-9]+$' || true)"
{ [ -n "$PHP_VER" ] && [ "$PHP_VER" != 00 ]; } || fail "Site par PHP select nahi. Website > $DOMAIN > PHP version > PHP-82 karein."
[ "$PHP_VER" -ge 82 ] || fail "Site PHP $PHP_VER par hai; 8.2+ chahiye. Website > $DOMAIN > PHP version > PHP-82 karein."
export PHP_BIN="/www/server/php/$PHP_VER/bin/php"
[ -x "$PHP_BIN" ] || fail "$PHP_BIN nahi mila."

# Composer and the scheduler need putenv / proc_open, which aaPanel disables by default.
FIXED=0
for ini in "/www/server/php/$PHP_VER/etc/php.ini" "/www/server/php/$PHP_VER/etc/php-cli.ini"; do
    [ -f "$ini" ] || continue
    for fn in putenv proc_open proc_get_status; do
        if grep -qE "^disable_functions\s*=.*\b${fn}\b" "$ini"; then
            [ -f "$ini.bak-pkpay" ] || cp "$ini" "$ini.bak-pkpay"
            sed -i -E "/^disable_functions/ { s/([=,])\s*${fn}\s*(,|$)/\1\2/; s/,,+/,/g; s/=\s*,/= /; s/,\s*$// }" "$ini"
            FIXED=1
        fi
    done
done
if [ "$FIXED" = 1 ]; then
    echo "putenv / proc_open enable kiye (backup: *.bak-pkpay)"
    /etc/init.d/php-fpm-"$PHP_VER" reload >/dev/null 2>&1 || /etc/init.d/php-fpm-"$PHP_VER" restart >/dev/null 2>&1 || true
fi

MODULES="$("$PHP_BIN" -m)"
MISSING=""
for ext in bcmath ctype curl fileinfo gd mbstring openssl pdo_mysql tokenizer; do
    grep -qix "$ext" <<<"$MODULES" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || fail "PHP extension missing:$MISSING. aaPanel > App Store > PHP-$PHP_VER > Setting > Install extensions se install karein, phir yehi command dobara."
echo "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') OK ($PHP_BIN)"

# -----------------------------------------------------------------------------
step "Git & Composer"
if ! command -v git >/dev/null 2>&1; then
    { apt-get update -qq && apt-get install -y -qq git; } >/dev/null 2>&1 \
        || yum install -y -q git >/dev/null 2>&1 \
        || fail "git install nahi ho saka."
fi
if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "$PHP_BIN" /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
COMPOSER_BIN="$(command -v composer)" || fail "composer install nahi ho saka."
export COMPOSER_BIN APP_DIR
echo "$(git --version) / $("$PHP_BIN" "$COMPOSER_BIN" --version 2>/dev/null | head -1)"

# -----------------------------------------------------------------------------
step "Code"
git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$APP_DIR" \
    || git config --global --add safe.directory "$APP_DIR"

if [ -d "$APP_DIR/.git" ]; then
    MODE=update
    echo "Pehle se install hai: update mode"
else
    MODE=install
    rm -f "$APP_DIR/index.html" "$APP_DIR/404.html"
    rm -rf /tmp/pkpay-src
    git clone -q "$REPO" /tmp/pkpay-src
    cp -a /tmp/pkpay-src/. "$APP_DIR/"
    rm -rf /tmp/pkpay-src
fi
cd "$APP_DIR"
echo "Commit: $(git log -1 --format='%h %s')"

# -----------------------------------------------------------------------------
if [ ! -f .env ]; then
    step "Database + .env"
    MYSQL_BIN="$(command -v mysql || echo /www/server/mysql/bin/mysql)"
    [ -x "$MYSQL_BIN" ] || fail "MySQL nahi mila. aaPanel > App Store se MySQL install karein."

    MYCNF="$(mktemp)"
    chmod 600 "$MYCNF"
    trap 'rm -f "$MYCNF"' EXIT
    mysql_root() {
        printf '[client]\nuser=root\npassword="%s"\n' "$ROOT_PW" > "$MYCNF"
        "$MYSQL_BIN" --defaults-extra-file="$MYCNF" "$@"
    }

    # MySQL root password: env var, else aaPanel's own record, else ask.
    ROOT_PW="${MYSQL_ROOT_PASSWORD:-}"
    if [ -z "$ROOT_PW" ]; then
        PY="$PANEL/pyenv/bin/python3"
        [ -x "$PY" ] || PY="$(command -v python3 || true)"
        if [ -n "$PY" ] && [ -f "$PANEL/data/default.db" ]; then
            ROOT_PW="$("$PY" -c "import sqlite3; print(sqlite3.connect('$PANEL/data/default.db').execute('select mysql_root from config where id=1').fetchone()[0])" 2>/dev/null || true)"
        fi
    fi
    if ! mysql_root -e 'SELECT 1' >/dev/null 2>&1; then
        [ -t 0 ] || fail "MySQL root password chahiye. Aise chalayein: MYSQL_ROOT_PASSWORD='...' bash <(curl -fsSL ...)"
        read -r -s -p "MySQL root password (aaPanel > Databases > Root password): " ROOT_PW
        echo
        mysql_root -e 'SELECT 1' >/dev/null 2>&1 || fail "MySQL root password galat hai."
    fi

    DB_PASS="$(openssl rand -hex 16)"
    mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

    cp .env.production.example .env
    sed -i \
        -e "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" \
        -e "s|^DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" \
        -e "s|^DB_USERNAME=.*|DB_USERNAME=$DB_USER|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" \
        -e "s|^ADMIN_EMAIL=.*|ADMIN_EMAIL=$ADMIN_EMAIL|" \
        -e "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"no-reply@$DOMAIN\"|" \
        .env
    echo "Database '$DB_NAME' + user '$DB_USER' tayyar; .env likh di (random DB password)"
fi

# -----------------------------------------------------------------------------
if [ "$MODE" = update ] && [ -f vendor/autoload.php ] && grep -q '^APP_KEY=base64:' .env; then
    step "Update (git pull, composer, migrate, cache)"
    bash deploy/update.sh
    bash deploy/cron.sh >/dev/null
else
    [ "$MODE" = update ] && git pull -q --ff-only origin main
    step "Install (composer, key, migrate, cache, permissions, cron)"
    SKIP_NEXT_STEPS=1 bash deploy/install.sh
fi

# -----------------------------------------------------------------------------
step "Admin"
ADMIN_PW="${ADMIN_PASSWORD:-$(openssl rand -hex 10)}"
RC=0
GATEWAY_ADMIN_PASSWORD="$ADMIN_PW" "$PHP_BIN" artisan gateway:create-admin "$ADMIN_EMAIL" \
    --name="Super Admin" --password-env=GATEWAY_ADMIN_PASSWORD --if-none >/dev/null || RC=$?
case "$RC" in
    0)
        NEW_ADMIN=1
        ( umask 077; printf 'Admin panel : https://%s/admin\nEmail       : %s\nPassword    : %s\nCreated     : %s\n' \
            "$DOMAIN" "$ADMIN_EMAIL" "$ADMIN_PW" "$(date '+%F %T')" > "$CREDS_FILE" )
        echo "Admin ban gaya: $ADMIN_EMAIL"
        ;;
    3)
        NEW_ADMIN=0
        echo "Admin pehle se mojood hai; password nahi badla."
        ;;
    *)
        fail "Admin create nahi ho saka (exit $RC)."
        ;;
esac
# aaPanel locks .user.ini (chattr +i); chown skips it, everything else goes to www.
chown -R www:www "$APP_DIR" 2>/dev/null || true

# -----------------------------------------------------------------------------
step "Web server: running directory /public + URL rewrite"
if [ "$WEB" = nginx ]; then
    sed -i -E "s#^(\s*root\s+)$APP_DIR/?;#\1$APP_DIR/public;#" "$NGINX_CONF"
    if ! grep -q 'try_files \$uri \$uri/ /index.php' "$REWRITE_CONF" 2>/dev/null; then
        mkdir -p "$(dirname "$REWRITE_CONF")"
        printf 'location / {\n    try_files $uri $uri/ /index.php?$query_string;\n}\n' > "$REWRITE_CONF"
    fi
    /www/server/nginx/sbin/nginx -t >/dev/null 2>&1 || fail "Nginx config test fail. Dekhein: /www/server/nginx/sbin/nginx -t"
    /etc/init.d/nginx reload >/dev/null 2>&1 || /www/server/nginx/sbin/nginx -s reload
else
    sed -i -E "s#(DocumentRoot\s+\")$APP_DIR/?\"#\1$APP_DIR/public\"#" "$APACHE_CONF"
    /etc/init.d/httpd reload >/dev/null 2>&1 || true
fi

# Same open_basedir protection aaPanel applies when the running directory is set in the GUI.
if [ ! -f public/.user.ini ]; then
    printf 'open_basedir=%s/:/tmp/\n' "$APP_DIR" > public/.user.ini
    chattr +i public/.user.ini 2>/dev/null || true
fi
echo "Web root: $APP_DIR/public"

# -----------------------------------------------------------------------------
step "Health check"
HTTPS_CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/up" || true)"
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 --resolve "$DOMAIN:80:127.0.0.1" "http://$DOMAIN/up" || true)"
if grep -qE 'ssl_certificate|SSLCertificateFile' "$VHOST" 2>/dev/null; then SSL=yes; else SSL=no; fi

echo
echo "=================================================================="
echo " Deployment complete ($MODE): https://$DOMAIN"
echo "------------------------------------------------------------------"
echo " Commit         : $(git log -1 --format='%h %s')"
echo " Health check   : https=$HTTPS_CODE  http=$HTTP_CODE   (200 ya 301 = theek)"
echo " Admin panel    : https://$DOMAIN/admin"
if [ "$NEW_ADMIN" = 1 ]; then
    echo " Admin email    : $ADMIN_EMAIL"
    echo " Admin password : $ADMIN_PW"
    echo "                  (abhi copy kar lein; $CREDS_FILE mein bhi save hai)"
else
    echo " Admin          : pehle se mojood. Password reset:"
    echo "                  cd $APP_DIR && $PHP_BIN artisan gateway:create-admin <email>"
fi
echo " API docs       : https://$DOMAIN/docs"
echo " Cron           : har minute, user www (check: crontab -u www -l)"
[ "$SSL" = yes ] || echo " WARNING        : SSL nahi laga. aaPanel > Website > $DOMAIN > SSL > Let's Encrypt > Apply"
echo "=================================================================="
