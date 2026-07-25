# syntax=docker/dockerfile:1

# =============================================================================
# Production image for self-hosting (build-from-source at a release tag).
#
#   git checkout vX.Y.Z
#   ./docker/gen-secrets.sh
#   # in .env, extend the COMPOSE_FILE the template ships so the build overlay
#   # (docker-compose.build.yml) stacks on top and restores a local `build:`:
#   COMPOSE_FILE=docker-compose.prod.yml:docker-compose.build.yml
#   docker compose up -d --build
#
# Development still uses Laravel Sail (compose.yaml); this image is a separate
# production path and does not replace it. Served with FrankenPHP (Caddy + a
# built-in PHP SAPI) in classic mode: one process per container, no nginx +
# PHP-FPM + supervisor juggling, and no extra Composer dependency (unlike
# Laravel Octane).
# =============================================================================

ARG PHP_VERSION=8.5

# -----------------------------------------------------------------------------
# Stage 1 — Composer: production PHP dependencies only, optimized autoloader.
# -----------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Cache the dependency layer on the manifest alone.
COPY composer.json composer.lock ./

# --no-scripts: the post-autoload-dump `artisan package:discover` boots the full
# app, which is deferred to the runtime entrypoint (config:cache) instead.
# --ignore-platform-req=ext-gd/ext-imagick/ext-ldap: this dependency stage only
# resolves and downloads packages; those extensions are installed in the runtime
# stage (image processing, LDAP directory auth), so the platform check is skipped
# here.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --ignore-platform-req=ext-gd \
        --ignore-platform-req=ext-imagick \
        --ignore-platform-req=ext-ldap

# Add the source and build an authoritative, optimized autoloader.
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# -----------------------------------------------------------------------------
# Stage 2 — Assets: compile the Vite/Inertia frontend (`npm ci && npm run build`).
#
# Built on the PHP base rather than a plain Node image because the Wayfinder
# Vite plugin shells out to `php artisan wayfinder:generate` during the build,
# so PHP + the vendor directory must be available here.
# -----------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine AS assets

RUN apk add --no-cache nodejs npm

WORKDIR /app

# Cache the npm layer on the lockfile alone.
COPY package.json package-lock.json ./
RUN npm ci

# Vendor (for Wayfinder codegen) + full source.
COPY --from=vendor /app/vendor ./vendor
COPY . .

# No VITE_* build args: the app name and the browser-facing Reverb settings are
# served to the SPA at runtime (an Inertia shared prop read at boot), so nothing
# operator-specific is baked into the bundle. One built image works for any host.
RUN npm run build

# -----------------------------------------------------------------------------
# Stage 3 — Runtime: slim FrankenPHP image with only what production needs.
# -----------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine AS runtime

# pdo_pgsql: Postgres. redis: phpredis client for the cache/session/queue drivers.
# pcntl/posix: queue worker + Reverb signal handling.
# intl/zip/opcache: framework recommendations + performance.
# gd/imagick: image processing for attachments (Intervention Image, installed via
# Composer) — EXIF-stripping and thumbnail generation. Imagick is the default
# driver (ATTACHMENT_IMAGE_DRIVER); GD is the fallback.
# ldap: directory bind authentication (LdapRecord); required by the package even
# when LDAP is not configured, since the extension is a hard dependency.
#
# Nothing here is resolved through pecl.php.net (#641). Everything but redis and
# imagick is bundled with PHP and compiled from the local source; those two are
# built from pinned GitHub sources instead. A PECL outage used to red the whole
# Docker workflow on a commit that changed nothing (#626), and the bounded retry
# below could only ride out a short one — `Upgrade script (build from source)`
# builds cold and uncached on purpose, so it had no other shield and failed
# through a multi-hour outage.
#
# The pins are compared against upstream monthly by
# .github/workflows/extension-pins.yml, which opens an issue when either falls
# behind: a silently frozen extension is its own defect.
ARG IMAGICK_VERSION=3.8.1
ARG PHPREDIS_VERSION=6.3.0

# The retry still earns its place — the clone, the archive fetch and apk all
# reach the network. A genuine error (a missing or incompatible extension) fails
# without touching the network, so it costs at most the 100s of backoff before
# giving up — bounded, not a multi-minute hang.
#
# phpredis is cloned rather than fetched as an archive: the codeload tarball
# omits the liblzf submodule and the build fails on the missing sources.
RUN set -eu; \
    max_attempts=5; \
    retry_delay=10; \
    retry() { \
        attempt=1; \
        until "$@"; do \
            if [ "$attempt" -ge "$max_attempts" ]; then \
                echo "$1 failed after $max_attempts attempts; giving up" >&2; \
                exit 1; \
            fi; \
            delay=$((attempt * retry_delay)); \
            echo "$1 failed (attempt $attempt/$max_attempts); retrying in ${delay}s" >&2; \
            sleep "$delay"; \
            attempt=$((attempt + 1)); \
        done; \
    }; \
    retry apk add --no-cache --virtual .phpredis-source git; \
    retry git clone \
            --depth 1 \
            --recurse-submodules \
            --branch "$PHPREDIS_VERSION" \
            https://github.com/phpredis/phpredis.git /tmp/phpredis; \
    retry install-php-extensions \
            pdo_pgsql \
            pcntl \
            posix \
            intl \
            zip \
            opcache \
            gd \
            ldap \
            /tmp/phpredis \
            "https://github.com/Imagick/imagick/archive/refs/tags/${IMAGICK_VERSION}.tar.gz"; \
    apk del --no-network .phpredis-source; \
    rm -rf /tmp/phpredis; \
    retry apk add --no-cache curl

# Production PHP/OPcache tuning.
COPY docker/php/production.ini $PHP_INI_DIR/conf.d/zz-production.ini

WORKDIR /app

# App source, production vendor, compiled assets.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
# The service worker ships from the web root, not `public/build`: a worker only
# controls paths below its own, so serving it from the bundle directory would
# scope it to `/build` and the app would stop being installable.
COPY --from=assets /app/public/service-worker.js ./public/service-worker.js

# Non-root runtime user. FrankenPHP listens on 8080 (>1024), so no extra
# capabilities are required to bind the port.
RUN set -eux; \
    addgroup -g 1000 -S www; \
    adduser -u 1000 -S -G www www; \
    mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/app/public \
        bootstrap/cache; \
    chown -R www:www storage bootstrap/cache public /data /config; \
    chmod +x docker/entrypoint.sh

ENV SERVER_NAME=:8080 \
    SERVER_ROOT=/app/public

USER www

EXPOSE 8080

# The FrankenPHP base image ships a HEALTHCHECK that curls Caddy's admin API on
# port 2019. This one image runs four different ways in the production stack
# (web server, reverb, queue:work, schedule:work), and only the web role serves
# Caddy — so that inherited probe would report the other three permanently
# unhealthy. Drop it and let docker-compose.prod.yml declare a real healthcheck
# per service where one is meaningful (the HTTP-serving `app` and `reverb`); the
# non-HTTP `queue`/`scheduler` workers intentionally run with no healthcheck.
HEALTHCHECK NONE

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
