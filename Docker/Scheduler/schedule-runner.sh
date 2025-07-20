#!/bin/sh

if [ ! -f ".env" ]; then
    echo "Creating env file from env $APP_ENV"
    cp ./Docker/Komiut/.env.komiut .env
else
    echo "env file exists."
fi

while :
do
  php artisan schedule:run >> /dev/null 2>&1
  sleep 60
done
