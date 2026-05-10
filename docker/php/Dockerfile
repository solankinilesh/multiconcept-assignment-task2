FROM php:8.3-fpm

# pdo_sqlite is the default DB; intl + zip + opcache are Symfony best-practice baseline.
# git + unzip are required by composer when packages are installed from source.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libsqlite3-dev sqlite3 \
    && docker-php-ext-install -j"$(nproc)" intl zip opcache pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dev-friendly php.ini overrides (verbose errors, opcache off so code edits take effect).
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
