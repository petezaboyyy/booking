FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        libcurl4-openssl-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" curl gd mbstring pdo_mysql xml zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN sed -ri \
    -e 's!AllowOverride None!AllowOverride All!g' \
    /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Copy source code into container
COPY . /var/www/html

# Ensure necessary directories exist and set appropriate permissions for Apache
RUN mkdir -p /var/www/html/datas /var/www/html/settings \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/datas /var/www/html/settings

EXPOSE 80
