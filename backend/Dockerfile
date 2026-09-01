FROM php:8.4-cli-alpine AS development

RUN apk add --no-cache \
    git \
    unzip \
    icu-dev \
    postgresql-dev \
    && docker-php-ext-install \
        intl \
        pdo_pgsql

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
