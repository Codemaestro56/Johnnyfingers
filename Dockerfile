FROM php:8.2-apache

# Suppress the fully qualified domain name warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Install the PDO MySQL extension inside the container
RUN docker-php-ext-install pdo pdo_mysql

COPY . /var/www/html/
EXPOSE 80
