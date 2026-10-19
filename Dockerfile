# PHP vanilla + Apache, con la raíz del sitio en public/ (para Render).
FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql \
 && a2enmod rewrite

# Configuración de producción: display_errors desactivado, errores al log
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

COPY . /var/www/html/

# Apache corre como www-data: necesita escribir el log de errores
RUN mkdir -p /var/www/html/storage/logs \
 && chown -R www-data:www-data /var/www/html/storage

EXPOSE 80
