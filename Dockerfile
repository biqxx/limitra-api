FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-req=ext-pcntl \
    --ignore-platform-req=ext-posix

COPY . .
RUN composer dump-autoload \
    --no-dev \
    --classmap-authoritative \
    --no-interaction \
    --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan l5-swagger:generate \
    && test -s storage/api-docs/api-docs.json \
    && php artisan route:list --path=api/documentation | grep -q 'api/documentation'

FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
        icu-libs \
        libpq \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
        linux-headers \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /app .
COPY docker/php.ini /usr/local/etc/php/conf.d/limitra.ini
COPY docker/entrypoint.sh /usr/local/bin/limitra-entrypoint

RUN chmod +x /usr/local/bin/limitra-entrypoint \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

ENTRYPOINT ["limitra-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.28-alpine AS web

WORKDIR /var/www/html

COPY --from=vendor /app/public ./public
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

RUN mkdir -p /var/www/html/storage/app/public \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage
