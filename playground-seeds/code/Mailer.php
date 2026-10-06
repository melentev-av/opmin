<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

interface Mailer
{
    public function send(string $to, string $body): bool;
}
