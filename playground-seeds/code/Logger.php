<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

interface Logger
{
    public function log(string $message): void;
}
