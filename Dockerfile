FROM php:8.2-apache

# Install OS dependencies required for PHP extensions and Composer
RUN apt-get update && apt-get install -y --no-install-recommends \
    libssl-dev \
    libcurl4-openssl-dev \
    pkg-config \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-install -j$(nproc) zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache & PHP
RUN a2enmod rewrite headers \
    && echo "date.timezone = Asia/Dhaka" > /usr/local/etc/php/conf.d/timezone.ini \
    && echo "upload_max_filesize = 25M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 25M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "display_errors = Off" > /usr/local/etc/php/conf.d/errors.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/errors.ini

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Install Composer dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set appropriate directory permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/assets/uploads 2>/dev/null || true

# Support dynamic port binding on Render ($PORT defaults to 80 if unset)
CMD ["sh", "-c", "sed -i \"s/80/${PORT:-80}/g\" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground"]

