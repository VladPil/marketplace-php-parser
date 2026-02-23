FROM php:8.4-fpm

RUN sed -i 's|http://deb.debian.org/debian|http://mirror.yandex.ru/debian|g; s|http://deb.debian.org/debian-security|http://mirror.yandex.ru/debian-security|g' /etc/apt/sources.list.d/debian.sources

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    unzip \
    curl \
    nginx \
    supervisor \
    && apt-get clean && rm -rf /var/lib/apt/lists/*


RUN docker-php-ext-install pdo pdo_pgsql intl zip opcache


RUN pecl install redis && docker-php-ext-enable redis


COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html


COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --ignore-platform-req=ext-swoole 2>/dev/null || true

COPY src/ src/
COPY config/ config/
COPY bin/ bin/
COPY templates/ templates/
COPY public/ public/
COPY migrations/ migrations/
RUN composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-swoole

COPY .docker/configs/admin/nginx.conf /etc/nginx/sites-available/default
COPY .docker/configs/admin/supervisor.conf /etc/supervisor/conf.d/app.conf
COPY .docker/configs/admin/opcache.ini /usr/local/etc/php/conf.d/99-opcache-off.ini
COPY .docker/configs/admin/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN mkdir -p /var/www/html/var && chown -R www-data:www-data /var/www/html/var

EXPOSE 80

CMD ["/entrypoint.sh"]
