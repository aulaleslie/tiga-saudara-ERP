FROM php:8.2-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        fontconfig \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libxext6 \
        libxrender1 \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        pcntl \
        pdo_mysql \
        soap \
        sockets \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/start-app /usr/local/bin/start-app
COPY docker/php/install-public-assets /usr/local/bin/install-public-assets

RUN chmod +x /usr/local/bin/start-app /usr/local/bin/install-public-assets

WORKDIR /var/www/html

EXPOSE 8000

CMD ["start-app"]
