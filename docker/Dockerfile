# The opmin image for CI and git packages: the static opmin binary plus the PHP of production as
# php.binary — OPcache (JIT off), pcov for the coverage of tests, git, composer.
#
#   docker build --build-arg PHP=8.3 -t ghcr.io/melentev-av/opmin:<version>-php8.3 <context with ./opmin>
#
# The context holds one file, `opmin`: the static linux binary of the image's architecture (the release
# workflow), or the PHAR for local tests on PHP 8.3+.
#
# No ENTRYPOINT: CI systems (GitHub `container:`, GitLab `image:`) run their own shell in the image.
ARG PHP=8.4
FROM php:${PHP}-cli

LABEL org.opencontainers.image.source="https://github.com/melentev-av/opmin" \
      org.opencontainers.image.description="opmin: minimizes PHP opcodes without changing behavior" \
      org.opencontainers.image.licenses="MIT"

# opcache is a shared extension of the official images up to 8.4 and built in since 8.5.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install bcmath intl pcntl zip \
    && (php -m | grep -qx 'Zend OPcache' || docker-php-ext-enable opcache) \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && printf 'opcache.enable_cli=1\nopcache.jit=disable\nopcache.jit_buffer_size=0\npcov.enabled=1\nmemory_limit=1G\n' \
        > /usr/local/etc/php/conf.d/zz-opmin.ini \
    # The mounted project belongs to another user than root of the container.
    && git config --system --add safe.directory '*' \
    && git config --system user.name opmin \
    && git config --system user.email opmin@localhost

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY opmin /usr/local/bin/opmin
RUN chmod 755 /usr/local/bin/opmin

WORKDIR /app
CMD ["opmin", "list"]
