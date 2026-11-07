<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\PlayerProfile;

use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Average\PreviousSeasonRecord;
use Bowlingliga\Domain\PlayerProfile\PlayerHistoryPoint;
use Bowlingliga\Domain\PlayerProfile\PlayerHistoryService;
use Bowlingliga\Domain\PlayerProfile\PlayerMatchdayInput;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the history of a player within a season.
 *
 * Validates: R39.3, R39.4, R39.6, R11.3, R11.5
 */
final class PlayerHistoryServiceTest extends TestCase
{
    private PlayerHistoryService $service;

    protected function setUp(): void
    {
        $this->service = new PlayerHistoryService();
    }

    public function testTheAverageAfterEachMatchdayAccumulatesTheSeasonGames(): void
    {
        $history = $this->service->history([
            $this->matchday(2, [180, 180, 180], 20),
            $this->matchday(1, [150, 150, 150], 40),
        ]);

        self::assertSame([1, 2], $this->numbers($history->points), 'Sorted by matchday counter.');
        self::assertEqualsWithDelta(150.0, $history->points[0]->average, 0.0001);
        self::assertEqualsWithDelta(165.0, $history->points[1]->average, 0.0001);
        self::assertEqualsWithDelta(180.0, $history->points[1]->dayAverage, 0.0001);
        self::assertSame(40, $history->points[0]->handicap);
        self::assertSame(6, $history->games());
        self::assertSame(2, $history->matchdaysPlayed());
        self::assertEqualsWithDelta(165.0, $history->lastAverage(), 0.0001);
        self::assertSame(20, $history->lastHandicap());
    }

    public function testWithoutAPreviousSeasonTheHistoryStartsWithTheFirstGame(): void
    {
        $history = $this->service->history([
            $this->matchday(1, []),
            $this->matchday(2, []),
            $this->matchday(3, [160, 170, 180], 32),
        ]);

        self::assertSame([3], $this->numbers($history->points));
        self::assertEqualsWithDelta(170.0, $history->points[0]->average, 0.0001);
    }

    public function testWithARecordTheHistoryStartsOnTheFirstMatchdayWithItsValue(): void
    {
        $history = $this->service->history(
            [$this->matchday(1, []), $this->matchday(2, [200, 200, 200], 8)],
            [],
            new PreviousSeasonRecord(30, 5400),
        );

        self::assertSame([1, 2], $this->numbers($history->points));
        self::assertEqualsWithDelta(180.0, $history->points[0]->average, 0.0001, 'The record alone on matchday 1.');
        self::assertFalse($history->points[0]->played());
        self::assertNull($history->points[0]->dayAverage);
        self::assertEqualsWithDelta((5400 + 600) / 33, $history->points[1]->average, 0.0001);
    }

    public function testThePreviousSeasonDropsOutAfterTheSeasonSetting(): void
    {
        $history = $this->service->history(
            [$this->matchday(1, [200, 200, 200]), $this->matchday(2, [200, 200, 200])],
            [new Game(100), new Game(100), new Game(100)],
            null,
            1,
        );

        self::assertEqualsWithDelta(150.0, $history->points[0]->average, 0.0001, 'Matchday 1 includes the previous season.');
        self::assertEqualsWithDelta(200.0, $history->points[1]->average, 0.0001, 'From matchday 2 only the season counts.');
    }

    public function testBlindAndGhostTeamGamesDoNotCount(): void
    {
        $history = $this->service->history([
            new PlayerMatchdayInput(1, 1, '2026-09-02', [new Game(150), new Game(90, true), new Game(120, false, true)]),
        ]);

        self::assertSame(1, $history->points[0]->games);
        self::assertEqualsWithDelta(150.0, $history->points[0]->average, 0.0001);
        self::assertEqualsWithDelta(150.0, $history->points[0]->dayAverage, 0.0001);
    }

    public function testAMissedMatchdayAfterTheStartKeepsItsPoint(): void
    {
        $history = $this->service->history([
            $this->matchday(6, [150, 150, 150], 40),
            $this->matchday(7, []),
        ]);

        self::assertSame([6, 7], $this->numbers($history->points));
        self::assertEqualsWithDelta(150.0, $history->points[1]->average, 0.0001);
        self::assertNull($history->points[1]->handicap);
        self::assertSame(40, $history->lastHandicap());
    }

    public function testWithoutAnyGameTheHistoryIsEmpty(): void
    {
        $history = $this->service->history([$this->matchday(1, [])]);

        self::assertTrue($history->isEmpty());
        self::assertNull($history->lastAverage());
        self::assertNull($history->lastHandicap());
    }

    /**
     * @param list<int> $nets
     */
    private function matchday(int $number, array $nets, ?int $handicap = null): PlayerMatchdayInput
    {
        return new PlayerMatchdayInput(
            100 + $number,
            $number,
            sprintf('2026-09-%02d', $number),
            array_map(static fn (int $net): Game => new Game($net), $nets),
            $handicap,
        );
    }

    /**
     * @param list<PlayerHistoryPoint> $points
     *
     * @return list<int>
     */
    private function numbers(array $points): array
    {
        return array_map(static fn (PlayerHistoryPoint $p): int => $p->matchdayNumber, $points);
    }
}
