#!/usr/bin/env bash
# Pull the latest code from GitHub and apply it (run as root on the server):
#
#   cd /www/wwwroot/pkpay.site && bash deploy/update.sh
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/pkpay.site}"
PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_BIN:-$(command -v composer)}"
export COMPOSER_ALLOW_SUPERUSER=1

cd "$APP_DIR"

# git refuses to work in a folder owned by another user (www) unless allowed.
git config --global --get-all safe.directory | grep -qx "$APP_DIR" || git config --global --add safe.directory "$APP_DIR"

"$PHP" artisan down --retry=15 || true
# Always bring the site back up, even if a step below fails.
trap '"$PHP" artisan up; chown -R www:www "$APP_DIR"' EXIT

git pull --ff-only origin main
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
"$PHP" artisan migrate --force
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo "Updated to $(git log -1 --format='%h %s')"
