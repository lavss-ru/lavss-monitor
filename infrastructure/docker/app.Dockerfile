FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    postgresql-dev \
    sqlite-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev

RUN docker-php-ext-install pdo pdo_pgsql pdo_sqlite mbstring zip fileinfo

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000

CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]
