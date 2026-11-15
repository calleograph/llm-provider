# PHP-FPM — существующее приложение без изменений структуры. Код в образе, данные — в томе APP_VAR_DIR, секреты — в окружении (.env вне образа).
ARG PHP_IMAGE=php:8.4-fpm-bookworm@sha256:b75642e8c89c321039f351466134df951576fb0f7017117b3931e9a19b908d6c
FROM ${PHP_IMAGE}

# ldap, zip — расширения приложения; poppler-utils и tesseract (rus+eng) — извлечение текста PDF и OCR сканов (FileProcessor).
RUN set -eux; \
    apt-get -o Acquire::Retries=5 update; \
    apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
        libldap2-dev libzip-dev libldap-common poppler-utils tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng ca-certificates; \
    docker-php-ext-configure ldap --with-libdir=lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH); \
    docker-php-ext-install -j"$(nproc)" ldap zip opcache; \
    apt-get purge -y --auto-remove libldap2-dev libzip-dev; \
    apt-get install -y --no-install-recommends libldap-2.5-0 libzip4; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qiE '^ldap$' && php -m | grep -qiE '^zip$'

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /srv/app
COPY --chown=root:root . /srv/app
# Тесты и служебное в рабочем образе не нужны, но phpunit запускается из этого же образа на этапе проверки — tests оставлены (без секретов).
RUN rm -rf /srv/app/var /srv/app/services /srv/app/docker/nginx /srv/app/.git; \
    mkdir -p /data/app && chown www-data:www-data /data/app

ENV APP_VAR_DIR=/data/app
USER www-data
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 CMD ["php", "/srv/app/bin/healthcheck.php"]

ARG APP_VERSION=0.0.0
ARG APP_GIT_COMMIT=unknown
ENV APP_VERSION=${APP_VERSION} APP_GIT_COMMIT=${APP_GIT_COMMIT}
