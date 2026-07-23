FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl gd mbstring pdo_mysql xml zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN sed -ri \
    -e 's!AllowOverride None!AllowOverride All!g' \
    /etc/apache2/apache2.conf

WORKDIR /var/www/html
