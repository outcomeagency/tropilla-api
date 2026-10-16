FROM php:8.4-fpm-alpine

# Dependencias del sistema + extensiones PHP para Laravel 13 sobre PostgreSQL/Redis.
# postgresql-client aporta pg_isready para que el entrypoint espere a la DB.
RUN apk add --no-cache \
        bash git unzip curl icu-libs libzip libpq postgresql-client \
        libpng libjpeg-turbo libwebp freetype \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS linux-headers icu-dev libzip-dev postgresql-dev \
        libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath gd zip exif pcntl intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY php.ini /usr/local/etc/php/conf.d/zz-tropilla.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# El contenedor corre con el UID/GID del host (ver compose.yaml) para que los
# archivos del bind mount (vendor, storage) no queden de root.
ENV COMPOSER_HOME=/tmp/composer \
    HOME=/tmp

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
