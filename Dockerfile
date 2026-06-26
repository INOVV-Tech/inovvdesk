FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libonig-dev \
    unzip \
    mariadb-client \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && mkdir -p /var/www/html/uploads /var/www/html/storage/tickets /var/www/html/backups \
    && chown -R www-data:www-data /var/www/html

RUN echo '<Directory /var/www/html>' > /etc/apache2/conf-available/foxdesk.conf \
    && echo 'AllowOverride All' >> /etc/apache2/conf-available/foxdesk.conf \
    && echo 'Require all granted' >> /etc/apache2/conf-available/foxdesk.conf \
    && echo '</Directory>' >> /etc/apache2/conf-available/foxdesk.conf \
    && a2enconf foxdesk

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]