# Dev image of the playground: the official php:<ver>-cli plus what the stock image lacks for
# opmin's own checks — pcov (coverage for Infection, coverage maps of project tests), pcntl (fork
# isolation of the harness), zip/git/unzip and composer (installing scenarios inside the container).
# Built by `bin/playground php` and `bin/playground wrapper` as opmin-dev:<ver>.
ARG PHP=8.4
FROM php:${PHP}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && printf 'pcov.enabled=1\nmemory_limit=1G\n' > /usr/local/etc/php/conf.d/zz-opmin.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
