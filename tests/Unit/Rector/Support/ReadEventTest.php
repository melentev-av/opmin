<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Rector\Support;

use Opmin\Rector\Support\ReadEvent;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * An effect stops a group of reads only when its write set meets the storage of the read.
 */
#[Test]
#[Covers(ReadEvent::class)]
final class ReadEventTest
{
    /**
     * @return iterable<string, array{ReadEvent::*, list<non-empty-string>|null, non-empty-string|null, bool}>
     */
    public static function effects(): iterable
    {
        yield 'anything, a named location' => [ReadEvent::EFFECT, null, 'app\foo::$p', true];
        yield 'anything, an unnamed location' => [ReadEvent::EFFECT, null, null, true];
        yield 'the location listed' => [ReadEvent::EFFECT, ['app\bar::$q', 'app\foo::$p'], 'app\foo::$p', true];
        yield 'other locations' => [ReadEvent::EFFECT, ['app\bar::$q'], 'app\foo::$p', false];
        yield 'nothing written' => [ReadEvent::EFFECT, [], 'app\foo::$p', false];
        yield 'something written, an unnamed location' => [ReadEvent::EFFECT, ['app\bar::$q'], null, true];
        yield 'nothing written, an unnamed location' => [ReadEvent::EFFECT, [], null, false];
        yield 'not an effect' => [ReadEvent::WRITE, null, 'app\foo::$p', false];
    }

    /**
     * @param ReadEvent::* $kind
     * @param list<non-empty-string>|null $writes
     * @param non-empty-string|null $location
     */
    #[DataProvider('effects')]
    public function effectMeetsLocation(string $kind, ?array $writes, ?string $location, bool $expected): void
    {
        $event = new ReadEvent($kind, null, null, 0, false, writes: $writes);

        Assert::same($event->mayWrite($location), $expected);
    }
}
