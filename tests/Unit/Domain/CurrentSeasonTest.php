<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain;

use Bowlingliga\Domain\CurrentSeason;
use PHPUnit\Framework\TestCase;

/**
 * The current season without a choice (R28.18): the running one, otherwise the
 * one that ended last, otherwise the next one.
 */
final class CurrentSeasonTest extends TestCase
{
    private const SEASONS = [
        ['id' => 1, 'start_date' => '2023-09-06', 'end_date' => '2024-07-03'],
        ['id' => 2, 'start_date' => '2024-09-04', 'end_date' => '2025-07-02'],
        // Created early, starts later.
        ['id' => 3, 'start_date' => '2025-09-03', 'end_date' => '2026-07-01'],
    ];

    public function testTheRunningSeasonCountsEvenIfALaterOneExists(): void
    {
        self::assertSame(2, $this->at('2025-05-14'));
    }

    public function testStartAndEndDateBelongToTheSeason(): void
    {
        self::assertSame(2, $this->at('2024-09-04'));
        self::assertSame(2, $this->at('2025-07-02'));
    }

    public function testInTheSummerBreakTheSeasonThatEndedLastCounts(): void
    {
        self::assertSame(1, $this->at('2024-08-01'));
        self::assertSame(2, $this->at('2025-07-03'));
    }

    public function testAfterTheLastSeasonItStaysCurrent(): void
    {
        self::assertSame(3, $this->at('2027-01-01'));
    }

    public function testBeforeTheFirstSeasonTheNextOneCounts(): void
    {
        self::assertSame(1, $this->at('2023-01-01'));
    }

    public function testOverlappingSeasonsPreferTheOneThatStartedLast(): void
    {
        $seasons = [
            ['id' => 7, 'start_date' => '2024-01-01', 'end_date' => '2024-12-31'],
            ['id' => 8, 'start_date' => '2024-06-01', 'end_date' => '2025-05-31'],
        ];

        self::assertSame(8, CurrentSeason::of($seasons, new \DateTimeImmutable('2024-07-01')));
    }

    public function testWithoutSeasonsThereIsNone(): void
    {
        self::assertNull(CurrentSeason::of([], new \DateTimeImmutable('2025-01-01')));
    }

    private function at(string $day): ?int
    {
        return CurrentSeason::of(self::SEASONS, new \DateTimeImmutable($day . ' 20:00'));
    }
}
