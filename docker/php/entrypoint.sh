#!/bin/bash
set -e

if [ ! -d "/var/www/vendor" ] || [ -z "$(ls -A /var/www/vendor 2>/dev/null)" ]; then
    echo "Installing dependencies..."
    cd /var/www && composer install --no-interaction --optimize-autoloader

    if [ ! -f /var/www/.env ]; then
        cp /var/www/.env.example /var/www/.env
    fi

    if [ -z "$APP_KEY" ] && ! grep -qE '^APP_KEY=base64:' /var/www/.env; then
        php artisan key:generate --force
    fi
fi

exec php-fpm
