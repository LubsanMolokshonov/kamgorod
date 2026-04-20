FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    curl \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql

RUN a2enmod rewrite headers

COPY ai-consultant/apache-config.conf /etc/apache2/sites-available/000-default.conf

RUN mkdir -p /var/www/html/logs && chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
