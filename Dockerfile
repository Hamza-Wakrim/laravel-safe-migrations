FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

# Install dependencies first so this layer is cached between source changes.
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

CMD ["vendor/bin/phpunit"]
