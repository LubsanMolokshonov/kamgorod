FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    librsvg2-bin \
    cron \
    antiword \
    poppler-utils \
    wv \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath zip

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configure Apache DocumentRoot
RUN sed -i 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# Setup cron jobs for email automation
RUN echo "*/5 * * * * www-data php /var/www/html/cron/process-email-journey.php >> /var/log/cron-email.log 2>&1" > /etc/cron.d/email-automation \
    && echo "*/5 * * * * www-data php /var/www/html/cron/process-webinar-emails.php >> /var/log/cron-webinar.log 2>&1" >> /etc/cron.d/email-automation \
    && echo "*/5 * * * * www-data php /var/www/html/cron/process-autowebinar-emails.php >> /var/log/cron-autowebinar.log 2>&1" >> /etc/cron.d/email-automation \
    && chmod 0644 /etc/cron.d/email-automation

# Expose port 80
EXPOSE 80

# Start cron and Apache
CMD service cron start && apache2-foreground
