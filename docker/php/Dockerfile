FROM php:8.3-fpm-alpine

# GD avec JPEG, WebP et FreeType : photos du studio, affiches, et billets dessinés par le serveur
# (nom de l'invité, QR code). Sans ces options, GD ne lit que le PNG.
RUN apk add --no-cache icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install intl pdo_pgsql pcntl zip opcache bcmath gd \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
CMD ["php-fpm"]
