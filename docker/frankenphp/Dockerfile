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

# Runtime PHP limits (upload size etc.) — aligned with the app's 10MB media cap; without this the
# stock 2M upload_max_filesize silently truncates larger uploads before Livewire sees them.
COPY docker/frankenphp/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

# Octane + FrankenPHP is launched via docker-compose `command`.
