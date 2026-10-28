#!/usr/bin/env bash
# First-time setup on the server (aaPanel). Run as root after .env is filled:
#
#   cd /www/wwwroot/pkpay.site && bash deploy/install.sh
#
# Override paths if needed: PHP_BIN=/www/server/php/82/bin/php bash deploy/install.sh
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/pkpay.site}"
PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_BIN:-$(command -v composer || true)}"
export COMPOSER_ALLOW_SUPERUSER=1

cd "$APP_DIR"

if [ ! -f .env ]; then
    echo "ERROR: .env not found. Run: cp .env.production.example .env  and fill DB_* first."
    exit 1
fi

if [ -z "$COMPOSER" ]; then
    echo "ERROR: composer not found. Install it first (see the deployment guide)."
    exit 1
fi

echo "==> PHP version"
"$PHP" -v | head -1

echo "==> Checking PHP extensions"
for ext in bcmath fileinfo gd mbstring openssl pdo_mysql; do
    "$PHP" -m | grep -qi "^${ext}$" || { echo "ERROR: PHP extension '${ext}' is missing"; exit 1; }
done

echo "==> Composer install (production)"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction

echo "==> App key"
grep -q '^APP_KEY=base64:' .env || "$PHP" artisan key:generate --force

echo "==> Database"
"$PHP" artisan config:clear
"$PHP" artisan migrate --force

echo "==> Caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo "==> Permissions"
chown -R www:www "$APP_DIR"
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +
chmod 640 .env

echo
echo "Done. Next steps:"
echo "  1) Create the admin:  $PHP artisan gateway:create-admin admin@pkpay.site"
echo "  2) Add the cron job:  cd $APP_DIR && sudo -u www $PHP artisan schedule:run >> /dev/null 2>&1"
echo "  3) Open https://pkpay.site/admin"
