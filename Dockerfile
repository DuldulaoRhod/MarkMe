
FROM php:8.2-apache

# Install PHP dependencies and ClamAV
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libcurl4-openssl-dev \
    clamav \
    clamav-freshclam \
    && docker-php-ext-configure gd \
       --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
       pdo_mysql gd curl exif \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache to listen on Render's port
RUN sed -ri 's/Listen 80/Listen 10000/' \
    /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' \
    /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY . /var/www/html

# Create application configuration
RUN cp /var/www/html/config/config.example.php /var/www/html/config/config.php

# Prepare upload and storage directories
RUN mkdir -p /var/www/html/uploads/designs \
    /var/www/html/storage/outbox \
    && chown -R www-data:www-data \
       /var/www/html/uploads \
       /var/www/html/storage \
    && chmod -R 775 \
       /var/www/html/uploads \
       /var/www/html/storage

# Ensure ClamAV database directory exists
RUN mkdir -p /var/lib/clamav \
    && chown -R clamav:clamav /var/lib/clamav

EXPOSE 10000

# Update malware definitions before starting Apache
CMD ["sh", "-c", "freshclam --stdout --no-warnings && exec apache2-foreground"]
