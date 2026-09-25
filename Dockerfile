# syntax=docker/dockerfile:1

# Stage 1: Build frontend assets
FROM node:22-alpine AS assets_builder

WORKDIR /app

COPY package.json yarn.lock ./
RUN yarn install --frozen-lockfile

COPY assets ./assets
COPY webpack.config.mjs ./
RUN yarn build

# Stage 2: Production PHP + Caddy runtime via FrankenPHP
FROM dunglas/frankenphp:php8.4-alpine AS app

# Install required PHP extensions
RUN install-php-extensions \
    intl \
    zip \
    pdo_mysql \
    pdo_pgsql

# Copy Composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Configure PHP production defaults
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    sed -i 's/variables_order = .*/variables_order = "EGPCS"/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/memory_limit = .*/memory_limit = 256M/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/upload_max_filesize = .*/upload_max_filesize = 32M/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/post_max_size = .*/post_max_size = 32M/' "$PHP_INI_DIR/php.ini" && \
    echo "opcache.enable_cli=1" >> "$PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini"

# Set default environment variables
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/data.db" \
    SERVER_NAME=:80

# Install dependencies in a separate layer for caching
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application source code
COPY . .

# Copy compiled assets from frontend build stage
COPY --from=assets_builder /app/public/build ./public/build

# Dump optimized autoload and pre-warmup production cache
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev && \
    APP_SECRET=build_time_secret php bin/console assets:install --no-debug public && \
    APP_SECRET=build_time_secret php bin/console cache:clear --no-debug --no-warmup && \
    APP_SECRET=build_time_secret php bin/console cache:warmup --no-debug

# Prepare var directory with permissions
RUN mkdir -p var/cache var/log var/data && \
    chown -R root:www-data var && \
    chmod -R 777 var

# Copy entrypoint script
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

EXPOSE 80

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
