FROM dunglas/frankenphp:php8.5

# PHP extensions required by the pinned stack (PG, Valkey, Octane worker mode)
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    pcntl \
    opcache \
    intl \
    zip \
    bcmath \
    gd \
    exif \
    redis \
    sodium

# Composer (pinned major 2)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Octane + FrankenPHP is launched via docker-compose `command`.
