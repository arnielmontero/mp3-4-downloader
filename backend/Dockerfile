# syntax=docker/dockerfile:1
# Build context: repository root (see docker-compose.yml).
#
#   docker build -f backend/Dockerfile --target app .
#
# One image serves two roles: PHP-FPM (REST API) and the download worker (php bin/worker.php).

############################################################
# 1. PHP dependencies
############################################################
FROM composer:2 AS vendor
WORKDIR /build
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

############################################################
# 2. Media tooling: Deno (JavaScript runtime yt-dlp needs for YouTube) with checksum verification
############################################################
FROM debian:bookworm-slim AS deno
ARG TARGETARCH
ARG DENO_VERSION=2.5.6
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl unzip \
    && rm -rf /var/lib/apt/lists/*
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
      amd64) triple=x86_64-unknown-linux-gnu ;; \
      arm64) triple=aarch64-unknown-linux-gnu ;; \
      *) echo "unsupported arch ${TARGETARCH}"; exit 1 ;; \
    esac; \
    base="https://github.com/denoland/deno/releases/download/v${DENO_VERSION}"; \
    curl -fsSL -o deno.zip "${base}/deno-${triple}.zip"; \
    curl -fsSL -o deno.zip.sha256sum "${base}/deno-${triple}.zip.sha256sum" || true; \
    if [ -s deno.zip.sha256sum ]; then \
      expected="$(awk '{print $1}' deno.zip.sha256sum)"; \
      echo "${expected}  deno.zip" | sha256sum -c -; \
    else echo "WARNING: no published checksum for deno ${DENO_VERSION}"; fi; \
    unzip deno.zip -d /out && chmod +x /out/deno

############################################################
# 3. Runtime image
############################################################
FROM php:8.3-fpm-bookworm AS app

# yt-dlp is installed from PyPI ("default" extra = yt-dlp-ejs challenge solver) into its own venv.
# Pin with: --build-arg YTDLP_VERSION=2026.09.27.232945   (empty = latest release at build time)
ARG YTDLP_VERSION=
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg python3 python3-venv ca-certificates curl util-linux procps \
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && python3 -m venv /opt/yt-dlp \
    && /opt/yt-dlp/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/yt-dlp/bin/pip install --no-cache-dir "yt-dlp[default]${YTDLP_VERSION:+==$YTDLP_VERSION}" \
    && ln -s /opt/yt-dlp/bin/yt-dlp /usr/local/bin/yt-dlp \
    && rm -rf /var/lib/apt/lists/* /root/.cache

COPY --from=deno /out/deno /usr/local/bin/deno

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www/backend
COPY backend/application ./application
COPY backend/public ./public
COPY backend/bin ./bin
COPY backend/composer.json backend/composer.lock ./
COPY --from=vendor /build/vendor ./vendor

# Storage lives outside the code tree and is mounted as volumes; pre-create it owned by www-data
# so named volumes inherit the right ownership.
RUN mkdir -p /var/www/storage/downloads /var/www/storage/temp /var/www/storage/jobs /var/www/storage/logs \
    && chown -R www-data:www-data /var/www/storage \
    && /opt/yt-dlp/bin/yt-dlp --version && deno --version | head -1 && ffmpeg -version | head -1

ENV STORAGE_PATH=/var/www/storage \
    YTDLP_BIN=/usr/local/bin/yt-dlp \
    FFMPEG_BIN=/usr/bin/ffmpeg \
    FFPROBE_BIN=/usr/bin/ffprobe \
    HOME=/tmp \
    APP_ENV=production

USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD php /var/www/backend/bin/healthcheck.php || exit 1
CMD ["php-fpm"]

############################################################
# 4. Test stage:  docker build -f backend/Dockerfile --target test .
#    Installs dev dependencies and runs the whole PHPUnit suite (unit + worker integration with the
#    fake yt-dlp and real FFmpeg). The build fails if any test fails.
############################################################
FROM app AS test
USER root
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader
COPY backend/phpunit.xml ./phpunit.xml
COPY tests /var/www/tests
RUN chmod +x /var/www/tests/fixtures/fake-yt-dlp     && chown -R www-data:www-data /var/www/backend /var/www/tests /var/www/storage
USER www-data
RUN php vendor/bin/phpunit --testdox --no-progress
