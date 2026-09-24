FROM php:8.3-fpm

# System packages Laravel/Composer/Node need
RUN apt-get update && apt-get install -y \
    git curl unzip libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# phpredis extension (REDIS_CLIENT=phpredis in .env)
RUN pecl install redis && docker-php-ext-enable redis

# Composer (PHP package manager)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js + npm (for building the Vue frontend)
RUN curl -fsSL https://deb.nodesource.com/setup_26.x | bash - \
    && apt-get install -y nodejs

WORKDIR /var/www/html
