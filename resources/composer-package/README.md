# opmin (PHAR)

> **Not published yet.** This is the PHAR wrapper for a future release: no package is made from this
> directory today. `composer require --dev melentev-av/opmin` currently installs the opmin sources with
> their dependencies (see the main README); for a conflict-free install use `opmin.phar` from the release
> page or the static binary.

The composer package of [opmin](https://github.com/melentev-av/opmin): the scoped PHAR of a release and a
`bin/opmin` that starts it, without dependencies, so `composer require --dev` never conflicts with the
project.

```bash
composer require --dev melentev-av/opmin
vendor/bin/opmin doctor
```

The static binary (see the main README) needs no PHP 8.3 and is the recommended way; this package is for
projects that pin the tool version in composer.json. A global `opmin` started in such a project hands the
run over to `vendor/bin/opmin`.

The package is made from `resources/composer-package/` of the opmin repository plus `opmin.phar` of the
release; do not edit it by hand.
