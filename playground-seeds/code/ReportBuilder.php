<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Repeated reads of readonly properties: a positive case for ExtractRepeatedPropertyFetchRector.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly ReportConfig $config,
    ) {}

    public function header(): string
    {
        return $this->config->title . ' (' . $this->config->locale . ")\n"
            . str_repeat('=', strlen($this->config->title) + strlen($this->config->locale) + 3);
    }
}
