FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.3.36-apache-bookworm

LABEL org.opencontainers.image.source="https://github.com/mirivlad/tms"
LABEL org.opencontainers.image.licenses="AGPL-3.0-or-later"

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod headers

# PHP2Shell (CVE-2026-109618 / CVE-2026-109619): refuse vulnerable runtimes.
RUN php -r 'exit(version_compare(PHP_VERSION, "8.3.36", ">=") ? 0 : 1);'

WORKDIR /var/www/html

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/uploads.ini /usr/local/etc/php/conf.d/tms-uploads.ini
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/tms-entrypoint

RUN chmod 0755 /usr/local/bin/tms-entrypoint \
    && mkdir -p /var/www/html/var/cache /var/www/html/var/storage/attachments /var/www/html/var/secrets \
    && chown -R www-data:www-data /var/www/html/var

ENTRYPOINT ["tms-entrypoint"]
CMD ["apache2-foreground"]
