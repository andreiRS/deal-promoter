# Toolchain image for the standalone WhatsApp gateway. Source is mounted at
# runtime via docker-compose; vendor/ is built by `composer install` against the
# mounted tree. Unlike the pipeline, this app has no database, so the pdo_pgsql
# extension and libpq-dev are intentionally dropped.
FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
    && docker-php-ext-install intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHPStan at level max can outgrow the stock 128M CLI worker limit; give headroom.
RUN echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/memory-limit.ini

ENV COMPOSER_ALLOW_SUPERUSER=1

# The monorepo root is mounted here by docker-compose; the app lives one level down.
WORKDIR /app/apps/whatsapp-service

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
