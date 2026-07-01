#!/bin/bash
set -e

if [ ! -d "/var/www/vendor" ] || [ -z "$(ls -A /var/www/vendor 2>/dev/null)" ]; then
    echo "Installing dependencies..."
    cd /var/www && composer install --no-interaction --optimize-autoloader
fi

exec php-fpm
