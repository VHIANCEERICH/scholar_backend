FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
