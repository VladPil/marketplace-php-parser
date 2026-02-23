#!/bin/bash
set -e

# Прогреваем кеш и выставляем права для www-data
php /var/www/html/bin/console cache:warmup --no-debug 2>/dev/null || true
chown -R www-data:www-data /var/www/html/var

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
