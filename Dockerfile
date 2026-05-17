# syntax=docker/dockerfile:1.9

# --- Base stage ---------------------------------------------------------
# FrankenPHP (Caddy + PHP in one image). Pinned to 1.x on PHP 8.4.
FROM dunglas/frankenphp:1-php8.4 AS base

ARG USER_ID=1000
ARG GROUP_ID=1000

# Install system packages needed for runtime + PHP extensions.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        acl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# install-php-extensions ships with the image.
# gd is required by endroid/qr-code (2FA setup QR generation) and dompdf
# (PDF logo embedding); both ship since Phase 10.5 / 10.6 and would 500
# without it on a fresh container. mbstring / fileinfo are pulled by the
# Symfony Validator + UploadedFile guess for logo uploads.
RUN install-php-extensions \
        pdo_mysql \
        intl \
        zip \
        pcntl \
        opcache \
        apcu \
        gd \
        mbstring \
        fileinfo \
        ldap

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create a non-root user matching the host UID/GID so files created inside
# the container are owned by the host user (no permission friction).
RUN groupadd --gid "${GROUP_ID}" app \
    && useradd --uid "${USER_ID}" --gid app --shell /bin/bash --create-home app \
    && mkdir -p /app /data/caddy /config/caddy \
    && chown -R app:app /app /data/caddy /config/caddy

WORKDIR /app

# Copy FrankenPHP configuration.
COPY --chown=app:app frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY --chown=app:app frankenphp/conf.d/app.ini $PHP_INI_DIR/conf.d/
COPY --chown=app:app frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# --- Development stage --------------------------------------------------
FROM base AS dev

# Pcov for fast code coverage. Xdebug optional — enable via XDEBUG_MODE env.
RUN install-php-extensions pcov xdebug \
    && echo "xdebug.mode=off" > $PHP_INI_DIR/conf.d/99-xdebug-default.ini

# Load-order: PHP scans conf.d/ alphabetically. `zz-` prefix ensures
# dev overrides win over base `app.ini` (without this, validate_timestamps=0
# from the base config clobbers validate_timestamps=1 → stale opcache).
COPY --chown=app:app frankenphp/conf.d/zz-app.dev.ini $PHP_INI_DIR/conf.d/

# APP_ENV is set via compose.yaml, not baked into the image,
# so PHPUnit's phpunit.dist.xml <server> override works.
ENV XDEBUG_MODE=off

USER app

# --- Production stage (placeholder for now, fleshed out near v1.0) -----
FROM base AS prod

ENV APP_ENV=prod
ENV APP_DEBUG=0

COPY --chown=app:app . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && composer dump-env prod \
    && php bin/console cache:clear \
    && php bin/console cache:warmup

USER app
