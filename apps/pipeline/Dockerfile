# Toolchain image for the Deal Pipeline. Source (apps/pipeline + packages/shared)
# is mounted at runtime via docker-compose; vendor/ is built by `composer install`
# against the mounted tree, so the path-repo packages resolve offline.
FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
    && docker-php-ext-install pdo pdo_pgsql intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHPStan at level max with the Doctrine extension loads ORM metadata and blows
# past the stock 128M CLI worker limit; give all PHP runs headroom.
RUN echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/memory-limit.ini

ENV COMPOSER_ALLOW_SUPERUSER=1

# The monorepo root is mounted here by docker-compose; the app lives one level down.
WORKDIR /app/apps/pipeline

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
