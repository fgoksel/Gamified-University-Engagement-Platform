# The PHP release is an exact one, so that everyone builds the same PHP. A tag such as
# php:8.3-fpm is not pulled again when an older image of that name is already on the machine,
# so a machine that built before a security release keeps the old PHP. A new tag is a new name,
# so Docker pulls it.
#
# 8.3.36 (21 Oct 2026) fixed CVE-2026-109618 and CVE-2026-109619, the critical remote code
# execution flaws. 8.3.37 (19 Nov 2026) fixed 13 more, three of them in PHP-FPM. The list is
# the NEWS file of php-src.
#
# To move to a newer release: change the tag, set OLDEST_RELEASE in tests/Feature/PhpImageTest.php
# to the same number, and run `docker compose up -d --build`.
FROM php:8.3.37-fpm

# System packages Laravel/Composer/Node need
RUN apt-get update && apt-get install -y \
    git curl unzip libzip-dev libpng-dev libonig-dev libicu-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# phpredis extension (REDIS_CLIENT=phpredis in .env)
RUN pecl install redis && docker-php-ext-enable redis

# Composer (PHP package manager)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js + npm (for building the Vue frontend)
RUN curl -fsSL https://deb.nodesource.com/setup_26.x | bash - \
    && apt-get install -y nodejs

WORKDIR /var/www/html
