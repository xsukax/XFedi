# XFedi: PHP serves the application, Python supervises private background jobs.
FROM php:8.3-fpm-alpine
RUN set -eux; \
    apk add --no-cache curl libcurl oniguruma sqlite-libs libxml2 python3 tzdata; \
    apk add --no-cache --virtual .php-build-deps $PHPIZE_DEPS curl-dev oniguruma-dev sqlite-dev libxml2-dev; \
    docker-php-ext-install -j2 curl mbstring pdo_sqlite dom; \
    apk del .php-build-deps; \
    php -r 'foreach (["pdo_sqlite","curl","mbstring","openssl","fileinfo","dom"] as $e) { if (!extension_loaded($e)) exit(1); }'; \
    mkdir -p /srv/app /var/lib/xsukax-fediverse; \
    chown -R www-data:www-data /srv/app /var/lib/xsukax-fediverse; \
    chmod 0700 /var/lib/xsukax-fediverse
COPY --chown=www-data:www-data index.php worker.py /srv/app/
COPY --chown=www-data:www-data app /srv/app/app
COPY --chown=www-data:www-data assets /srv/app/assets
COPY --chown=www-data:www-data bin /srv/app/bin
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-xfedi.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-xfedi.conf
USER www-data
WORKDIR /srv/app
EXPOSE 9000
CMD ["php-fpm", "-F"]
