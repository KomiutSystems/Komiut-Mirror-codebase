#!/bin/bash
if [ ! -f "vendor/autoload.php" ]; then
    composer install --no-progress --no-interaction
fi

if [ ! -f ".env" ]; then
    echo "Creating env file from env $APP_ENV"
    cp ./Docker/Komiut/.env.komiut .env
else
    echo "env file exists."
fi

# Create required Laravel storage folders
mkdir -p storage/framework/cache
mkdir -p storage/framework/views
mkdir -p storage/framework/sessions
mkdir -p storage/logs
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

#php artisan migrate
#php artisan db:seed
php artisan key:generate
php artisan cache:clear
php artisan config:clear
php artisan route:clear

php artisan serve --port=$PORT --host=0.0.0.0 --env=.env
exec docker-php-entrypoint "$@"
