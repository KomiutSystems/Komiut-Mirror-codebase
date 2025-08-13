#!/bin/sh

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

while :
do
  php artisan schedule:run >> /dev/null 2>&1
  sleep 60
done
