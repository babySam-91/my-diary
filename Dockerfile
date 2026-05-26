FROM php:8.2-apache

# Install PostgreSQL support and other needed extensions
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Enable error reporting for debugging (remove in production)
RUN docker-php-ext-configure opcache --enable-opcache \
    && docker-php-ext-install opcache

# Copy your entire project
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Enable PHP error logging
RUN echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/error.ini \
    && echo "display_errors = On" >> /usr/local/etc/php/conf.d/error.ini
