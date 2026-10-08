FROM php:8.2-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring dom xmlwriter zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-progress

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY . .

CMD ["php-fpm"]