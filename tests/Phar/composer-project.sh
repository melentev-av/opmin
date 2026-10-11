#!/bin/sh
# The scoped PHAR in a project that has its own, other versions of nikic/php-parser and Rector in vendor/:
# count, verify and optimize must work without the prefixed copies inside the PHAR colliding with them.
#
#   tests/Phar/composer-project.sh .build/phar/opmin.phar
#
# Needs php (8.3+, with opcache), composer, git and the network (composer downloads the project's packages).

set -eu

PHAR="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "== a project with php-parser 4 and Rector 1"
mkdir -p "$WORK/project/src"
cd "$WORK/project"
cat > composer.json <<JSON
{
    "name": "acme/project",
    "require": {"php": ">=8.3"},
    "require-dev": {"nikic/php-parser": "^4.19", "rector/rector": "^1.2"},
    "autoload": {"psr-4": {"Acme\\\\": "src/"}},
    "config": {"allow-plugins": false}
}
JSON
cat > src/Text.php <<'PHP'
<?php

declare(strict_types=1);

namespace Acme;

final class Text
{
    public function size(string $s): int
    {
        return strlen($s) + strlen(trim($s));
    }
}
PHP
printf '<?php\nfunction twice(int $x): int { return $x * 2; }\n' > a.php
printf '<?php\nfunction twice(int $x): int { return $x + $x; }\n' > same.php
printf '<?php\nfunction twice(int $x): int { return $x * 3; }\n' > other.php
printf 'paths: [src]\n' > opmin.yaml

composer install --no-interaction --no-progress --quiet
composer show --direct | grep -E 'php-parser|rector'
cp "$PHAR" opmin.phar

echo "== the PHAR in the project"
php opmin.phar --version
php opmin.phar doctor --set=commands.phpstan=null || true
php opmin.phar count src
php opmin.phar verify a.php same.php
if php opmin.phar verify a.php other.php > /dev/null; then echo "a changed behavior was accepted"; exit 1; fi

git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -q -m init
php opmin.phar optimize --dry-run --no-interaction --set=commands.phpstan=null src | tee "$WORK/optimize.log"
grep -q 'opcodes (-' "$WORK/optimize.log" || { echo "optimize did not reduce the opcodes of src/Text.php"; exit 1; }
grep -q 'Rector failed' "$WORK/optimize.log" && { echo "Rector failed"; exit 1; }
echo "OK: the PHAR works in a project with other php-parser and Rector versions"
