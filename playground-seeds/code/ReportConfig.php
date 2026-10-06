<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

final class ReportConfig
{
    public function __construct(
        public readonly string $title,
        public readonly string $locale,
    ) {}
}
