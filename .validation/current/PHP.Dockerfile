FROM php:8.3.36-cli
RUN set -eux; \
    docker-php-ext-install -j2 pdo_mysql; \
    yes '' | pecl install redis; \
    docker-php-ext-enable redis; \
    php -r 'if(PHP_VERSION!=="8.3.36" || !extension_loaded("redis") || !extension_loaded("pdo_mysql")) exit(1);'
