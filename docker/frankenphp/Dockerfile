FROM dunglas/frankenphp:1.13.5-php8.5-trixie@sha256:667c5fffd348d9c3c260803d75956f1d9aace7e4b7331d8893de6f101d7e0e57

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
    sodium \
    pcov

# qpdf (Apache-2.0) counts the pages of uploaded PDFs for the media library
# (App\Modules\Media\Services\PdfPages). Pinned to the Debian trixie package, as the base image is
# pinned by digest: a security fix (trixie-security) is taken by bumping this pin. Once a Debian
# point release drops this revision the build fails here on purpose: bump the pin then too.
RUN apt-get update \
    && apt-get install -y --no-install-recommends qpdf=12.2.0-1 \
    && rm -rf /var/lib/apt/lists/*

# Composer (pinned major 2)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Runtime PHP limits (upload size etc.) — aligned with the app's 10MB media cap; without this the
# stock 2M upload_max_filesize silently truncates larger uploads before Livewire sees them.
COPY docker/frankenphp/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

# Octane + FrankenPHP is launched via docker-compose `command`.
