# syntax=docker/dockerfile:1.7

ARG PHP_IMAGE=php:8.3-fpm-bookworm
ARG COMPOSER_IMAGE=composer:2.8
ARG NODE_IMAGE=node:22-alpine
ARG NGINX_IMAGE=nginx:1.28-alpine

FROM ${PHP_IMAGE} AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4 \
        libfreetype6 \
        libicu72 \
        libjpeg62-turbo \
        libonig5 \
        libpng16-16 \
        libzip4 \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        libcurl4-openssl-dev \
        libxml2-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
        xml \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        libcurl4-openssl-dev \
        libxml2-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM php-base AS vendor

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .

# Remove generated Laravel caches inside the image
RUN rm -f bootstrap/cache/config.php \
    bootstrap/cache/packages.php \
    bootstrap/cache/services.php \
    bootstrap/cache/events.php \
    bootstrap/cache/routes-*.php

RUN mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-plugins \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
    && APP_ENV=production APP_DEBUG=false ECF_ENABLED=false php artisan package:discover --ansi

FROM ${NODE_IMAGE} AS assets

WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY --from=vendor /var/www/html/vendor ./vendor
RUN mkdir -p storage/framework/views
RUN npm run build

FROM php-base AS app

WORKDIR /var/www/html
ENV APP_ENV=production \
    APP_DEBUG=false \
    ECF_ENABLED=false \
    PORTABILITY_BACKUP_ENABLED=false

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /src/public/build /var/www/html/public/build
COPY docker/php/zz-app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/entrypoint.sh /usr/local/bin/comercios-entrypoint

RUN chmod 0555 /usr/local/bin/comercios-entrypoint \
    && mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache

USER www-data
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/comercios-entrypoint"]
CMD ["php-fpm", "-F"]

FROM ${NGINX_IMAGE} AS web

COPY deploy/nginx/nginx.conf /etc/nginx/nginx.conf
COPY deploy/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY deploy/nginx/private-storage.conf /etc/nginx/snippets/private-storage.conf
COPY --from=assets /src/public /var/www/html/public

RUN rm -f /var/www/html/public/storage \
    && addgroup -S -g 33 appfiles \
    && addgroup nginx appfiles \
    && mkdir -p /srv/public-storage/products \
        /var/cache/nginx \
    && chown -R nginx:appfiles /srv/public-storage \
    && chown -R nginx:nginx /var/cache/nginx

USER nginx
EXPOSE 8080
