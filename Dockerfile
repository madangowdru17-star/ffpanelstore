FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install curl mbstring pdo_sqlite \
    && for mpm in mpm_event mpm_worker; do a2dismod "$mpm" 2>/dev/null || true; done \
    && a2enmod mpm_prefork rewrite headers \
    && test "$(find /etc/apache2/mods-enabled -maxdepth 1 -name 'mpm_*.load' | wc -l)" -eq 1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/zz-hex-shop.ini
COPY docker-entrypoint.sh /usr/local/bin/hex-shop-entrypoint
RUN chmod +x /usr/local/bin/hex-shop-entrypoint \
    && chown -R www-data:www-data /var/www/html

ENV PORT=8080
EXPOSE 8080
CMD ["/usr/local/bin/hex-shop-entrypoint"]
