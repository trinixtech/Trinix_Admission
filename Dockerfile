# Local development image. Production deployment is set up with the hosting decision (Phase 9).

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY vite.config.ts tsconfig.json ./
RUN npx vite build

FROM php:8.3-cli-alpine
RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql intl zip pcntl bcmath opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize && php artisan package:discover --ansi \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
ENV PHP_CLI_SERVER_WORKERS=8
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
