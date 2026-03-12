FROM php:8.2-cli

RUN apt-get update && \
    apt-get install -y libsqlite3-dev && \
    rm -rf /var/lib/apt/lists/* && \
    docker-php-ext-install pdo pdo_sqlite

# Настройки PHP для загрузки файлов
RUN echo "upload_max_filesize = 50M" >> /usr/local/etc/php/php.ini && \
    echo "post_max_size = 55M" >> /usr/local/etc/php/php.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/php.ini && \
    echo "max_execution_time = 60" >> /usr/local/etc/php/php.ini

WORKDIR /app

COPY telechat.php /app/telechat.php

# Создаём папки для базы данных и файлов
RUN mkdir -p /data/uploads && chmod 777 /data && chmod 777 /data/uploads
RUN mkdir -p /app/data && chmod 777 /app && chmod 777 /app/data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "telechat.php"]
