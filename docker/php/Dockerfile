# PHP 8.3 FPM for AlbaBook (plan.md §3.1, §29).
FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-albabook.ini

WORKDIR /var/www/albabook

# Run as the host user's uid in development so files written into the bind
# mount (storage/, vendor/) stay editable outside the container.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" www-data
USER www-data
