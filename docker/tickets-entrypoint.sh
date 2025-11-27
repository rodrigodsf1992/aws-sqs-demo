#!/bin/bash

cd /var/www

php artisan migrate --force
if [ $? -ne 0 ]; then
    echo "Migration failed"
    exit 1
fi

php-fpm
if [ $? -ne 0 ]; then
    echo "Failed to start PHP-FPM"
    exit 1
fi
httpd -DFOREGROUND > /dev/null