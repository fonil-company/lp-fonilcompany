# Landing Fonil Group — PHP + Apache (Easypanel)
FROM php:8.3-apache

RUN a2enmod rewrite headers expires deflate \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && echo 'ServerTokens Prod\nServerSignature Off' > /etc/apache2/conf-enabled/zz-security.conf

COPY . /var/www/html/
RUN rm -rf /var/www/html/Dockerfile /var/www/html/.dockerignore /var/www/html/.git* \
 && mkdir -p /var/www/html/private/leads_backup \
 && chown -R www-data:www-data /var/www/html/private

EXPOSE 80
