<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\FixtureHistory;
use Bowlingliga\Application\ResultEntry\FixtureLogEntry;
use Bowlingliga\Application\ResultEntry\FixtureLogReader;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * The history of a fixture is only for logged-in admins.
 */
final class FixtureHistoryTest extends TestCase
{
    public function testAdminsSeeTheHistory(): void
    {
        $history = new FixtureHistory($this->reader(), new SwitchableWriteAccess(true));

        self::assertCount(1, $history->entries(100));
    }

    public function testWithoutAdminLoginTheHistoryIsNotRead(): void
    {
        $reader = $this->reader();
        $history = new FixtureHistory($reader, new SwitchableWriteAccess(false));

        try {
            $history->entries(100);
            self::fail('Without an admin login the history must not be read.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(0, $reader->reads, 'Not even read.');
    }

    private function reader(): FixtureLogReader
    {
        return new class () implements FixtureLogReader {
            public int $reads = 0;

            public function entries(int $fixtureId): array
            {
                $this->reads++;

                return [new FixtureLogEntry('2026-10-21 20:15:00', 'away', null, 'net', 11, 'Anna', 1, null, '170')];
            }
        };
    }
}
