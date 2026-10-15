# Image for bin/bench --cost: the official PHP CLI image of one version with valgrind (callgrind).
ARG PHP=8.5
FROM php:${PHP}-cli
RUN apt-get update && apt-get install -y --no-install-recommends valgrind && rm -rf /var/lib/apt/lists/* \
 && (php -m | grep -qx 'Zend OPcache' || docker-php-ext-enable opcache)
