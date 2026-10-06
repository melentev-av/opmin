<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Side effects through interfaces: the order of logger and mailer calls is behavior.
 */
final class Notifier
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Mailer $mailer,
    ) {}

    public function notify(string $to, string $body): bool
    {
        $this->logger->log('sending to ' . $to);
        $ok = $this->mailer->send($to, $body);
        $this->logger->log($ok ? 'sent' : 'failed');

        return $ok;
    }
}
