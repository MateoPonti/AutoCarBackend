# PHP vanilla + Apache, con la raíz del sitio en public/ (para Render).
FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql \
 && a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

COPY . /var/www/html/

EXPOSE 80
