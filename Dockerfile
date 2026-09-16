FROM php:8.3-fpm-alpine

ARG APP_ENV=production

RUN apk add --no-cache icu-libs libpq libxml2 libzip oniguruma sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libxml2-dev libzip-dev oniguruma-dev postgresql-dev sqlite-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath dom intl mbstring opcache pcntl pdo_pgsql pdo_sqlite simplexml xml xmlwriter zip \
    && apk del .build-deps

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN mkdir -p bootstrap/cache storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/testing storage/framework/views storage/logs \
    && composer install --no-interaction --prefer-dist --optimize-autoloader \
        $(if [ "$APP_ENV" = "production" ]; then echo "--no-dev"; fi) \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm", "-F"]
