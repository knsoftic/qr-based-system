#!/usr/bin/env bash
# Install (or refresh) the Laravel scheduler cron job. Run as root:
#
#   cd /www/wwwroot/pkpay.site && bash deploy/cron.sh
#
# The job goes into the crontab of the "www" user, so files it writes stay
# owned by www. Safe to run again: the old entry for this site is replaced,
# other cron jobs are kept.
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/pkpay.site}"
PHP="$(command -v "${PHP_BIN:-php}" || true)"
CRON_USER="${CRON_USER:-www}"

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: run as root."
    exit 1
fi

if [ -z "$PHP" ]; then
    echo "ERROR: PHP not found. Run: export PHP_BIN=/www/server/php/82/bin/php"
    exit 1
fi

LINE="* * * * * cd $APP_DIR && $PHP artisan schedule:run >> /dev/null 2>&1"

# Read the current jobs first (minus this site's old entry), then write them back with the new line.
CURRENT="$(crontab -u "$CRON_USER" -l 2>/dev/null | grep -vF "cd $APP_DIR && " || true)"
{ [ -n "$CURRENT" ] && printf '%s\n' "$CURRENT"; echo "$LINE"; } | crontab -u "$CRON_USER" -

echo "Cron job set for user $CRON_USER:"
crontab -u "$CRON_USER" -l | grep -F "cd $APP_DIR && "
