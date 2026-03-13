FROM php:8.2-cli

RUN apt-get update && \
    apt-get install -y libsqlite3-dev && \
    rm -rf /var/lib/apt/lists/* && \
    docker-php-ext-install pdo pdo_sqlite

WORKDIR /app

COPY telechat.php index.php

# Создаём папку для БД с правами
RUN mkdir -p /data && chmod 777 /data
RUN mkdir -p /app/data && chmod 777 /app/data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
