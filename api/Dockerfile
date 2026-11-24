# Imagen de producción de la API (la usa docker-compose.yml en la raíz, desplegado con Dokploy).

# --- Dependencias de Composer, sin las de desarrollo ---
FROM composer:2 AS dependencias
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# --- Servidor: Apache + PHP ---
FROM php:8.4-apache

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql zip intl bcmath opcache

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && a2enmod rewrite headers
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-cierre.ini"
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/iniciar.sh /usr/local/bin/iniciar
RUN sed -i 's/\r$//' /usr/local/bin/iniciar && chmod +x /usr/local/bin/iniciar \
    && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/security.conf \
    && echo 'ServerSignature Off' >> /etc/apache2/conf-available/security.conf

WORKDIR /var/www/html
COPY --from=dependencias --chown=www-data:www-data /app /var/www/html
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1/up') === false ? 1 : 0);"

ENTRYPOINT ["iniciar"]
CMD ["apache2-foreground"]
