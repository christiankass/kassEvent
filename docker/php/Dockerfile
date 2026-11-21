FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev libpng-dev postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl pdo_pgsql pcntl zip opcache bcmath gd \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
CMD ["php-fpm"]
