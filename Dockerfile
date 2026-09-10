FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod headers

WORKDIR /var/www/html

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/tms-entrypoint

RUN chmod 0755 /usr/local/bin/tms-entrypoint \
    && mkdir -p /var/www/html/var/cache \
    && chown -R www-data:www-data /var/www/html/var

ENTRYPOINT ["tms-entrypoint"]
CMD ["apache2-foreground"]
