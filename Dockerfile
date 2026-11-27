FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev cron gzip mawk coreutils util-linux ca-certificates \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && a2enmod headers rewrite remoteip \
 && printf 'ServerTokens Prod\nServerSignature Off\nRemoteIPHeader CF-Connecting-IP\nAlias /robots.txt /var/www/html/public/robots.php\nAlias /sitemap.xml /var/www/html/public/sitemap.php\n' > /etc/apache2/conf-enabled/app.conf \
 && printf 'memory_limit=512M\nexpose_php=Off\nopcache.enable=1\n' > /usr/local/etc/php/conf.d/app.ini

COPY . /var/www/html
COPY docker/crontab /etc/cron.d/app
RUN chmod 0644 /etc/cron.d/app && chmod +x /var/www/html/docker/entrypoint.sh \
 && chown -R www-data:www-data /var/www/html

EXPOSE 80
VOLUME ["/var/www/html/data"]
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
