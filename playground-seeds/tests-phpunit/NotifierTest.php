<?php

declare(strict_types=1);

namespace PlaygroundSeeds\Tests;

use PHPUnit\Framework\TestCase;
use PlaygroundSeeds\FileJournal;
use PlaygroundSeeds\Logger;
use PlaygroundSeeds\Mailer;
use PlaygroundSeeds\Notifier;

final class NotifierTest extends TestCase
{
    public function testNotifyLogsAroundSending(): void
    {
        $log = [];
        $logger = new class($log) implements Logger {
            public function __construct(private array &$log) {}

            public function log(string $message): void
            {
                $this->log[] = $message;
            }
        };
        $mailer = new class($log) implements Mailer {
            public function __construct(private array &$log) {}

            public function send(string $to, string $body): bool
            {
                $this->log[] = "mail {$to}";

                return $body !== '';
            }
        };

        self::assertTrue((new Notifier($logger, $mailer))->notify('a@b.c', 'hi'));
        self::assertSame(['sending to a@b.c', 'mail a@b.c', 'sent'], $log);
    }

    public function testJournalAppends(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'journal');
        $journal = new FileJournal($file);

        $journal->append('one');
        $journal->append('two');

        self::assertSame("one\ntwo\n", file_get_contents($file));
        unlink($file);
    }
}
