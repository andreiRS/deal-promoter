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

ENV COMPOSER_ALLOW_SUPERUSER=1

# The monorepo root is mounted here by docker-compose; the app lives one level down.
WORKDIR /app/apps/pipeline

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
