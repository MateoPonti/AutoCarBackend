# PHP vanilla + Apache, con la raíz del sitio en public/ (para Render).
FROM php:8.3-apache

# pdo_pgsql necesita las cabeceras de libpq; Composer necesita unzip para bajar los paquetes
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev unzip \
 && docker-php-ext-install pdo pdo_pgsql \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Configuración de producción: display_errors desactivado, errores al log
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

# Composer (binario oficial)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY . /var/www/html/

# Instala las dependencias de producción (vendor/) y deja el autoload optimizado
RUN cd /var/www/html \
 && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
 && rm -rf /root/.composer /root/.cache/composer

# Apache corre como www-data: necesita escribir el log de errores
RUN mkdir -p /var/www/html/storage/logs \
 && chown -R www-data:www-data /var/www/html/storage

EXPOSE 80
