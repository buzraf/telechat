FROM php:8.2-apache

# Install PostgreSQL extension
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy app files
COPY . /var/www/html/

# Apache config — point to telechat/ subdirectory
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        AllowOverride All\n\
        Require all granted\n\
        Options -Indexes\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# PHP config for production
RUN echo "upload_max_filesize = 32M\n\
post_max_size = 32M\n\
max_execution_time = 300\n\
memory_limit = 256M\n\
display_errors = Off\n\
log_errors = On" > /usr/local/etc/php/conf.d/telechat.ini

EXPOSE 80

CMD ["apache2-foreground"]
