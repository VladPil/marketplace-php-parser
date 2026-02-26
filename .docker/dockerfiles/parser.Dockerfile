FROM php:8.4-cli

RUN sed -i 's|http://deb.debian.org/debian|http://mirror.yandex.ru/debian|g; s|http://deb.debian.org/debian-security|http://mirror.yandex.ru/debian-security|g' /etc/apt/sources.list.d/debian.sources

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    unzip \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*


RUN docker-php-ext-install pdo pdo_pgsql pcntl sockets


RUN pecl install --configureoptions 'enable-openssl="yes" enable-swoole-curl="yes"' swoole && docker-php-ext-enable swoole


RUN pecl install redis && docker-php-ext-enable redis


COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app


COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts 2>/dev/null || true


COPY src/ src/
COPY config/ config/
COPY bin/ bin/
COPY templates/ templates/
COPY migrations/ migrations/
RUN composer install --no-dev --optimize-autoloader

CMD ["php", "bin/console", "parser:run"]
