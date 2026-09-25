#!/bin/sh
set -e

# Ensure runtime directories exist
mkdir -p var/cache var/log var/data

# Fix permissions for the runtime directories
chmod -R 777 var/cache var/log var/data

# Migrate legacy sqlite database file if found at var/data.db
if [ -f var/data.db ] && [ ! -f var/data/data.db ]; then
    echo ">> Migrating legacy var/data.db to persistent volume var/data/data.db..."
    cp var/data.db var/data/data.db
    chmod 666 var/data/data.db
fi

# If running frankenphp or console commands, run database initialization
if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    # Detect if database is SQLite or external service
    case "$DATABASE_URL" in
        sqlite:*)
            echo ">> Using SQLite database."
            mkdir -p var/data
            ;;
        *)
            echo ">> Checking external database connectivity..."
            MAX_RETRIES=30
            COUNT=0
            until php bin/console doctrine:database:create --if-not-exists --no-interaction > /dev/null 2>&1 || [ $COUNT -ge $MAX_RETRIES ]; do
                echo ">> Waiting for database to become available... ($COUNT/$MAX_RETRIES)"
                COUNT=$((COUNT+1))
                sleep 2
            done
            ;;
    esac

    echo ">> Updating database schema..."
    php bin/console doctrine:schema:update --force --no-interaction || true

    echo ">> Importing default hazard & precautionary statements..."
    php bin/console app:import-data --no-interaction || true

    # Fix permissions on runtime files
    chmod -R 777 var/cache var/log var/data 2>/dev/null || true

    echo ">> Application initialized successfully."
fi

exec "$@"
