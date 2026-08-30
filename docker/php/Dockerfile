# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4.24
ARG COMPOSER_VERSION=2.8.12

FROM composer:${COMPOSER_VERSION} AS composer

FROM php:${PHP_VERSION}-cli-bookworm AS development

ARG APP_UID=1000
ARG APP_GID=1000

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libzip-dev \
        unzip; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        zip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-application.ini

RUN set -eux; \
    groupadd --gid "${APP_GID}" app; \
    useradd --uid "${APP_UID}" --gid "${APP_GID}" --create-home --shell /bin/bash app; \
    mkdir -p /app/vendor /home/app/.composer; \
    chown -R app:app /app /home/app

ENV COMPOSER_HOME=/home/app/.composer

WORKDIR /app

USER app

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
