# syntax=docker/dockerfile:1

# ---------- stage 1: build front-end assets (Vite / Tailwind 4 / DaisyUI 5) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
# Blade files are scanned by Tailwind's @source globs, so they must be present at build time.
COPY resources ./resources
COPY vite.config.js tailwind.config.js postcss.config.cjs ./
# app.css @source globs point at vendor/ and storage/, neither of which exists in
# this stage; create them empty so the globs resolve to nothing instead of failing.
RUN mkdir -p vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
             storage/framework/views \
    && npm run build

# ---------- stage 2: composer dependencies ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# artisan isn't present yet, so skip the post-install scripts that call it.
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-interaction \
      --prefer-dist \
      --optimize-autoloader

# ---------- stage 3: runtime ----------
FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache nginx supervisor oniguruma-dev icu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath opcache \
    && rm -rf /var/cache/apk/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-allaclone.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# storage/ and bootstrap/cache must be writable by php-fpm and nginx (both run as
# www-data here). The sqlite file that backs sessions/cache/quests lives in database/.
RUN mkdir -p storage/framework/{sessions,views,cache/data} storage/logs database \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R u+rwX storage bootstrap/cache database

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
