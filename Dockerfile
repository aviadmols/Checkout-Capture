# Production image: FrankenPHP (Caddy + PHP 8.4) serving public/ on $PORT.
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions pdo_pgsql intl zip opcache bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production

# Dependencies first so code-only changes reuse this layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
# dump-autoload also runs package:discover and filament:upgrade, which publishes Filament's assets.
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod +x docker/start.sh

EXPOSE 8080
CMD ["docker/start.sh"]
