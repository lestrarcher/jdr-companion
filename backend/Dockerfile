# Debian avoids incomplete directory rewinds observed on Windows bind mounts.
# See docs/docker-service-discovery.md before switching back to Alpine.
FROM php:8.4-cli-bookworm AS development

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libicu-dev \
    libpq-dev \
    && docker-php-ext-install \
        intl \
        pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer run-script post-install-cmd

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
