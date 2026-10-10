# Third-party licenses

Packages bundled into the opmin PHAR and binary (`composer licenses --no-dev`). All are compatible with MIT.

| Package | Version | License |
|---|---|---|
| internal/container | 1.1.0 | BSD-3-Clause |
| internal/destroy | 1.0.0 | BSD-3-Clause |
| internal/path | 1.4.0 | BSD-3-Clause |
| nikic/php-parser | v5.9.0 | BSD-3-Clause |
| phpstan/phpdoc-parser | 2.3.6 | MIT |
| phpstan/phpstan | 2.3.0 | MIT |
| psr/container | 2.0.2 | MIT |
| rasuvaeff/property-testing-core | v1.2.0 | BSD-3-Clause |
| rector/rector | 2.7.0 | MIT |
| symfony/console | v7.4.20 | MIT |
| symfony/deprecation-contracts | v3.7.1 | MIT |
| symfony/polyfill-ctype | v1.37.0 | MIT |
| symfony/polyfill-intl-grapheme | v1.43.0 | MIT |
| symfony/polyfill-intl-normalizer | v1.43.0 | MIT |
| symfony/polyfill-mbstring | v1.43.0 | MIT |
| symfony/process | v7.4.19 | MIT |
| symfony/service-contracts | v3.7.3 | MIT |
| symfony/string | v7.4.19 | MIT |
| symfony/yaml | v7.4.20 | MIT |
| yiisoft/injector | 1.2.1 | BSD-3-Clause |

## The static binary

The binary is the PHAR above glued to a static PHP built by [static-php-cli](https://static-php.dev)
(`extensions` in `.github/actions/binary/action.yml`). Besides the packages above it contains:

| Component | License |
|---|---|
| PHP (the `micro` SAPI and the extensions ctype, filter, mbstring, openssl, pcntl, pdo, pdo_sqlite, phar, posix, sqlite3, tokenizer, zlib) | PHP License 3.01 |
| OpenSSL | Apache-2.0 |
| SQLite | Public domain |
| zlib | Zlib |
| musl libc (Linux binaries) | MIT |

All are compatible with MIT. No LGPL or GPL library is linked: the `iconv` extension (GNU libiconv) is left out
on purpose.

## The Docker image

`ghcr.io/melentev-av/opmin` is the official `php:<version>-cli` image (Debian) with the binary above, pcov
(PHP License 3.01), git and composer (MIT) added; the licenses of the Debian packages are in the image under
`/usr/share/doc/*/copyright`.

