FROM php:8.2-cli

RUN apt-get update && apt-get install -y unzip git curl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . /app

EXPOSE 80

CMD ["php", "/app/servidorWebsocket.php", "80"]
#esto es un ejemplo 