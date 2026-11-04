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
    tzdata \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-install -j$(nproc) zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure Timezone (Asia/Dhaka UTC+06:00) for Container OS and PHP
RUN ln -snf /usr/share/zoneinfo/Asia/Dhaka /etc/localtime \
    && echo "Asia/Dhaka" > /etc/timezone \
    && echo "date.timezone = Asia/Dhaka" > /usr/local/etc/php/conf.d/timezone.ini

# Configure Apache & PHP Security & Upload Settings
RUN a2enmod rewrite headers \
    && echo "upload_max_filesize = 25M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 25M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "display_errors = Off" > /usr/local/etc/php/conf.d/errors.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/errors.ini

# Set working directory
WORKDIR /var/www/html

# Copy application files (owned by root by default)
COPY . /var/www/html/

# Install Composer dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Security hardening: Keep application source read-only for Apache runtime user.
# Grant write permissions exclusively to designated upload directories.
RUN chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && mkdir -p /var/www/html/assets/uploads/avatars \
                /var/www/html/assets/uploads/evidence \
                /var/www/html/uploads/media \
    && chown -R www-data:www-data /var/www/html/assets/uploads /var/www/html/uploads \
    && chmod -R 775 /var/www/html/assets/uploads /var/www/html/uploads

# Support dynamic port binding on Render ($PORT defaults to 80 if unset)
CMD ["sh", "-c", "sed -i \"s/^Listen 80/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost \\*:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
