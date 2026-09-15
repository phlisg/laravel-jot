ARG PHP_VERSION=8.3
FROM dunglas/frankenphp:php${PHP_VERSION}

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN install-php-extensions zip
