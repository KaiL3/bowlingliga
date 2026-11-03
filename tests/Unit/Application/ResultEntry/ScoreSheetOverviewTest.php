<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\ScoreSheetOverview;
use Bowlingliga\Application\ResultEntry\OverviewEntry;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see ScoreSheetOverview} (task 24.1).
 *
 * Validates: R30.1, R30.19
 *
 * Checks that the overview returns all fixtures (R30.1) and filters the ones
 * still to be filled in (status "offen"/"in Bearbeitung") correctly (R30.19).
 */
final class ScoreSheetOverviewTest extends TestCase
{
    private function entry(int $id, string $status): OverviewEntry
    {
        return new OverviewEntry($id, 1, 1, 'Alpha', 'Beta', $status);
    }

    public function testShowsAllEntries(): void
    {
        $reader = new FixedOverviewReader([
            $this->entry(1, OverviewEntry::OPEN),
            $this->entry(2, OverviewEntry::COMPLETE),
        ]);

        $entries = (new ScoreSheetOverview($reader))->showOverview(1);

        self::assertCount(2, $entries);
    }

    public function testFiltersToBeFilled(): void
    {
        $reader = new FixedOverviewReader([
            $this->entry(1, OverviewEntry::OPEN),
            $this->entry(2, OverviewEntry::IN_PROGRESS),
            $this->entry(3, OverviewEntry::COMPLETE),
        ]);

        $toFill = (new ScoreSheetOverview($reader))->showToBeFilled(1);

        self::assertCount(2, $toFill, 'Only "open" and "in progress" are to be filled in (R30.19).');
        self::assertSame([1, 2], array_map(static fn (OverviewEntry $e): int => $e->fixtureId, $toFill));
    }

    public function testCompleteEntryIsNotToBeFilled(): void
    {
        self::assertFalse($this->entry(1, OverviewEntry::COMPLETE)->isToBeFilled());
        self::assertTrue($this->entry(1, OverviewEntry::OPEN)->isToBeFilled());
        self::assertTrue($this->entry(1, OverviewEntry::IN_PROGRESS)->isToBeFilled());
    }
}
