FROM php:8.2-cli

RUN apt-get update && apt-get install -y libsqlite3-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_sqlite

WORKDIR /app

COPY telechat.php /app/index.php

RUN chmod 777 /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
