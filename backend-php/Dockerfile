FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=vendor /app/vendor /var/www/html/vendor
COPY . /var/www/html
COPY docker/php-secrets-entrypoint.sh /usr/local/bin/zapcore-php-entrypoint
RUN chmod 0755 /usr/local/bin/zapcore-php-entrypoint \
    && chown -R www-data:www-data /var/www/html/storage

ENTRYPOINT ["/usr/local/bin/zapcore-php-entrypoint"]
CMD ["apache2-foreground"]
