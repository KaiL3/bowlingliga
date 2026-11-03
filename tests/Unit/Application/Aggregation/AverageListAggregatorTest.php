<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Aggregation;

use Bowlingliga\Application\Aggregation\GameRawData;
use Bowlingliga\Application\Aggregation\AverageListAggregator;
use Bowlingliga\Application\Aggregation\PlayerRawData;
use Bowlingliga\Domain\Honours\Gender;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see AverageListAggregator} (option A, step 2).
 *
 * Validates: R2.1, R2.4, R2.15, R20.4, R27.13
 *
 * Checks the domain exclusion rule (games marked blind and ghost team results
 * do not count), skipping players without an includable game, the empty
 * average list of a team without games and passing on the frozen handicap.
 * The raw data access is an in-memory fake; the pure AverageListService is
 * used for real.
 */
final class AverageListAggregatorTest extends TestCase
{
    private function net(int $value): GameRawData
    {
        return new GameRawData($value, false, false);
    }

    private function blind(int $value): GameRawData
    {
        return new GameRawData($value, true, false);
    }

    private function ghosts(int $value): GameRawData
    {
        return new GameRawData($value, false, true);
    }

    private function aggregator(PlayerRawData ...$player): AverageListAggregator
    {
        return new AverageListAggregator(new FixedAverageListRawDataReader($player));
    }

    public function testOnlyRealGamesAreIncluded(): void
    {
        // Anna: 3 real + 1 blind + 1 ghost team -> only the 3 real ones count.
        $anna = new PlayerRawData('1', 'Anna', Gender::FEMALE, [
            $this->net(150),
            $this->net(160),
            $this->net(170),
            $this->blind(200),
            $this->ghosts(190),
        ], 20);

        $averageList = $this->aggregator($anna)->averageList(10, 5);

        self::assertCount(1, $averageList->rows);
        $row = $averageList->rows[0];
        self::assertSame(3, $row->numberOfGames, 'Blind and ghost team games are left out (R27.13, R20.4).');
        self::assertSame(480, $row->netPins, '150+160+170 = 480, without blind/ghost team.');
        self::assertSame(20, $row->handicap, 'The frozen handicap is passed on (R2.8).');
    }

    public function testPlayerWithoutIncludableGameIsSkipped(): void
    {
        // Ben only has blind/ghost team games -> no row.
        $ben = new PlayerRawData('2', 'Ben', Gender::MALE, [
            $this->blind(200),
            $this->ghosts(180),
        ], 15);
        $anna = new PlayerRawData('1', 'Anna', Gender::FEMALE, [$this->net(150)], 20);

        $averageList = $this->aggregator($ben, $anna)->averageList(10, 5);

        self::assertCount(1, $averageList->rows, 'A player without a countable game gets no row.');
        self::assertSame('Anna', $averageList->rows[0]->playerName);
    }

    public function testTeamWithoutGamesGivesEmptyAverageList(): void
    {
        $ben = new PlayerRawData('2', 'Ben', Gender::MALE, [$this->blind(200)], null);

        $averageList = $this->aggregator($ben)->averageList(10, 5);

        self::assertTrue($averageList->isEmpty(), 'Without countable games the average list is empty (R2.4).');
    }

    public function testWithoutHandicapHdcStaysEmpty(): void
    {
        $anna = new PlayerRawData('1', 'Anna', Gender::FEMALE, [
            $this->net(150),
            $this->net(160),
            $this->net(170),
        ], null);

        $averageList = $this->aggregator($anna)->averageList(10, 5);

        $row = $averageList->rows[0];
        self::assertNull($row->handicap, 'Without a frozen handicap the HCP field stays empty (R2.15).');
        self::assertNull($row->averageGross, 'Without a handicap there is no gross average (R2.15).');
    }

    public function testOrderIsKeptForBestSeries(): void
    {
        // Order: 100, 200, 150 (real), a blind one in between -> best series
        // from the 3 real ones in order = 100+200+150 = 450.
        $anna = new PlayerRawData('1', 'Anna', Gender::FEMALE, [
            $this->net(100),
            $this->blind(300),
            $this->net(200),
            $this->net(150),
        ], 10);

        $averageList = $this->aggregator($anna)->averageList(10, 5);

        self::assertSame(450, $averageList->rows[0]->bestSeriesNet, 'The best series uses the real games in order (R2.12).');
    }
}
