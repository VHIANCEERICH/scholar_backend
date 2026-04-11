FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    tesseract-ocr \
    tesseract-ocr-eng \
    zip \
    unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY *.php /var/www/html/
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html

ENV TESSERACT_PATH=/usr/bin/tesseract
EXPOSE 9000
USER www-data
# trigger