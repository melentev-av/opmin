<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bridge\Rector\Testing\RectorTestingPlugin;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'unit',
            location: ['tests/Unit'],
        ),
        new SuiteConfig(
            name: 'integration',
            location: ['tests/Integration'],
        ),
        # Rector rules carry #[TestRectorFixtures] and are tested "inline": the plugin finds the
        # rules in these directories and runs every *.php.inc fixture as a data set.
        new SuiteConfig(
            name: 'rector',
            location: ['src/Rector', 'tests/Rector'],
            plugins: [new RectorTestingPlugin()],
        ),
    ],
);
