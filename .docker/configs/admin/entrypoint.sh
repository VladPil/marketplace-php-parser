#!/bin/bash
set -e

# Очищаем старый кеш и прогреваем новый, выставляем права для www-data
php /var/www/html/bin/console cache:clear 2>/dev/null || true
php /var/www/html/bin/console cache:warmup 2>/dev/null || true
chown -R www-data:www-data /var/www/html/var

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
