FROM php:8.2-apache

# Включаем нужные расширения PHP
RUN docker-php-ext-install pdo pdo_sqlite
RUN apt-get update && apt-get install -y libsqlite3-dev && rm -rf /var/lib/apt/lists/*

# Включаем mod_rewrite для Apache
RUN a2enmod rewrite headers

# Копируем файлы
COPY telechat.php /var/www/html/index.php
COPY .htaccess /var/www/html/.htaccess

# Папка для базы данных с правами на запись
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

# Apache конфиг — разрешаем .htaccess
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Options -Indexes +FollowSymLinks\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/telechat.conf \
    && a2enconf telechat

# Открываем порт
EXPOSE 80

# Запуск Apache
CMD ["apache2-foreground"]
