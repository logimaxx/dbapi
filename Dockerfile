FROM ubuntu:25.10

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y software-properties-common curl unzip \
    && add-apt-repository ppa:ondrej/php -y \
    && apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y \
        nginx \
        supervisor \
        php8.3-cli \
        php8.3-fpm \
        php8.3-mysql \
        php8.3-redis \
        php8.3-opcache \
        php8.3-apcu \
        php8.3-mbstring \
        php8.3-xml \
        php8.3-zip \
        php8.3-yaml \
        php8.3-curl \
    && php -r "if (!extension_loaded('yaml')) { fwrite(STDERR, 'ext-yaml is required but not loaded\n'); exit(1); }" \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY configs/nginx.conf /etc/nginx/sites-available/default
COPY configs/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY configs/php.ini /etc/php/8.3/fpm/php.ini
COPY configs/php-fpm.conf /etc/php/8.3/fpm/php-fpm.conf
COPY configs/www.conf /etc/php/8.3/fpm/pool.d/www.conf
COPY configs/opcache.ini /etc/php/8.3/fpm/conf.d/99-opcache.ini

RUN sed -i 's/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/g' /etc/php/8.3/fpm/php.ini \
    && mkdir -p /run/php /app/apis

WORKDIR /app

COPY src/composer.json src/composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

COPY src/ ./

RUN chown -R www-data:www-data /app/public

COPY scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --start-period=15s --retries=3 \
    CMD curl -f http://127.0.0.1/health || exit 1

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
