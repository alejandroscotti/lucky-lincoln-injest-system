# Build context: repository root (Railway auto-detects this Dockerfile)
FROM node:22-alpine AS web-build
WORKDIR /web
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=
ARG VITE_REVERB_SCHEME=
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY
ENV VITE_REVERB_HOST=$VITE_REVERB_HOST
ENV VITE_REVERB_PORT=$VITE_REVERB_PORT
ENV VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME
COPY packages/web/package.json packages/web/package-lock.json* ./
RUN npm install
COPY packages/web/ .
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --ignore-platform-reqs

FROM php:8.3-cli-alpine
RUN apk add --no-cache curl nginx libzip-dev icu-dev oniguruma-dev freetype-dev libjpeg-turbo-dev libpng-dev \
    mariadb mariadb-client \
    freetype libjpeg-turbo libpng \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql zip intl gd pcntl \
    && apk del freetype-dev libjpeg-turbo-dev libpng-dev \
    && mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld

WORKDIR /var/www/html
COPY backend/ .
COPY --from=vendor /app/vendor ./vendor
COPY --from=web-build /web/dist ./public

COPY backend/docker/entrypoint.sh /entrypoint.sh
COPY backend/docker/nginx.conf /etc/nginx/nginx.conf
RUN chmod +x /entrypoint.sh \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

EXPOSE 8000
HEALTHCHECK CMD sh -c 'curl -f "http://127.0.0.1:${PORT:-8000}/api/health?ready=1" && pgrep -f "artisan schedule:work" >/dev/null || exit 1'
ENTRYPOINT ["/entrypoint.sh"]
