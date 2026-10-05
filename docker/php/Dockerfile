FROM php:8.2-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        fontconfig \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxext6 \
        libxrender1 \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && CPPFLAGS="$PHP_CPPFLAGS -I/usr/src/php" docker-php-ext-install -j1 \
        dom \
        simplexml \
        xml \
        xmlreader \
        xmlwriter \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        exif \
        gd \
        intl \
        mbstring \
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
