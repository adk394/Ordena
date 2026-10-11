FROM php:8.4-apache

ENV SESSION_COOKIE_SECURE=1

RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf \
    && a2enmod headers \
    && mkdir -p /var/lib/php/sessions \
    && chown www-data:www-data /var/lib/php/sessions \
    && chmod 1733 /var/lib/php/sessions

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-ordena.ini
COPY deploy/apache.conf /etc/apache2/conf-available/ordena.conf
RUN a2enconf ordena

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html

EXPOSE 10000

CMD ["apache2-foreground"]
