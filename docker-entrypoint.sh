#!/bin/bash
set -e

if [ ! -f .env ] && [ -f .env.example ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

echo "Installing dependencies..."
composer install --no-interaction

# Run setup script to create tables
echo "Running database setup..."
php bin/setup.php

echo "Starting PHP development server..."
php -S 0.0.0.0:80 -t public public/router.php

echo "You can access the app on 0.0.0.0:80"
