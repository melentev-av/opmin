<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Package;

use Opmin\Module\Package\Requirements;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Requirements::class)]
final class RequirementsTest
{
    public function readsThePhpConstraintAndTheExtensions(): void
    {
        $requirements = Requirements::fromComposerJson('{"require": {"php": " ^8.2 ", "ext-MBString": "*", "ext-json": "*", "psr/log": "^3"}}');

        Assert::same($requirements->php, '^8.2');
        Assert::same($requirements->extensions, ['json', 'mbstring']);
    }

    public function choosesTheImagesThePackageAllows(): void
    {
        Assert::same(Requirements::fromComposerJson('{"require": {"php": "^8.2"}}')->imageMinors(), ['8.5', '8.4', '8.3', '8.2']);
        Assert::same(Requirements::fromComposerJson('{"require": {"php": ">=7.4 <8.2"}}')->imageMinors(), ['8.1']);
        Assert::same(Requirements::fromComposerJson('{"require": {"php": ">=8.1.10"}}')->imageMinors(), ['8.5', '8.4', '8.3', '8.2', '8.1']);
        Assert::same(Requirements::fromComposerJson('{"require": {"php": "^7.4"}}')->imageMinors(), []);
        Assert::same(Requirements::fromComposerJson('{}')->imageMinors(), Requirements::IMAGE_MINORS);
    }

    public function checksAPhpVersion(): void
    {
        $requirements = Requirements::fromComposerJson('{"require": {"php": "^8.3"}}');

        Assert::true($requirements->allowsPhp('8.4.1'));
        Assert::false($requirements->allowsPhp('8.2.30'));
        Assert::true(Requirements::fromComposerJson('{"require": {"php": "*"}}')->allowsPhp('8.1.0'));
    }

    public function namesTheMissingExtensions(): void
    {
        $requirements = Requirements::fromComposerJson('{"require": {"ext-intl": "*", "ext-zend-opcache": "*", "ext-ctype": "*"}}');

        Assert::same($requirements->missingExtensions(['Core', 'ctype', 'Zend OPcache']), ['intl']);
    }
}
