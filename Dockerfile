FROM php:8.2-cli

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
COPY . /var/www/html/
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html

ENV TESSERACT_PATH=/usr/bin/tesseract
EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} router.php"]
