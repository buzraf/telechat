FROM php:8.2-cli

# Системные зависимости
RUN apt-get update && \
    apt-get install -y \
        libsqlite3-dev \
        libpq-dev \
        postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# PHP расширения — SQLite + PostgreSQL
RUN docker-php-ext-install pdo pdo_sqlite pdo_pgsql pgsql

# PHP настройки
RUN echo "upload_max_filesize = 50M" >> /usr/local/etc/php/php.ini && \
    echo "post_max_size = 55M" >> /usr/local/etc/php/php.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/php.ini && \
    echo "max_execution_time = 60" >> /usr/local/etc/php/php.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/php.ini && \
    echo "log_errors = On" >> /usr/local/etc/php/php.ini

WORKDIR /app

# Копируем файлы
COPY telechat.php /app/telechat.php
COPY index.php /app/index.php

# Папка для SQLite (fallback) и загрузок
RUN mkdir -p /data/uploads && \
    chmod 777 /data && \
    chmod 777 /data/uploads

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
