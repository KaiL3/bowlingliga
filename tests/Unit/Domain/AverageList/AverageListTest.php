<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\AverageList;

use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\AverageList\AverageList;
use Bowlingliga\Domain\AverageList\AverageListPlayerInput;
use Bowlingliga\Domain\AverageList\AverageListService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see AverageListService} (task 13.1).
 *
 * Validates: R2.3, R2.4, R2.14, R2.15
 *
 * Checked are the descending sort by net average (R2.3), the empty average
 * list for a team without played games (R2.4), the correct sums and team
 * averages of the total row (R2.14) and the empty HDC field with otherwise
 * unchanged values for a player without handicap (R2.15).
 */
final class AverageListTest extends TestCase
{
    private AverageListService $service;

    protected function setUp(): void
    {
        $this->service = new AverageListService();
    }

    /**
     * Validates: R2.3
     *
     * The player rows are sorted descending by their net average.
     */
    public function testPlayersAreSortedDescendingByNetAverage(): void
    {
        $inputs = [
            $this->input('a', 'Anton', netResults: [100, 120], handicap: 20),   // Ø 110,00
            $this->input('b', 'Berta', netResults: [200, 220], handicap: 10),   // Ø 210,00
            $this->input('c', 'Cara', netResults: [150, 150], handicap: 0),     // Ø 150,00
        ];

        $averageList = $this->service->averageList($inputs);

        $averages = array_map(
            static fn ($row): float => $row->averageNet,
            $averageList->rows,
        );

        self::assertSame([210.0, 150.0, 110.0], $averages);
        self::assertSame(['b', 'c', 'a'], array_map(
            static fn ($row): string => $row->playerId,
            $averageList->rows,
        ));
    }

    /**
     * Validates: R2.4
     *
     * A team without played games leads to an empty average list; the calling
     * layer then shows the message "no averages".
     */
    public function testTeamWithoutPlayedGamesReturnsEmptyAverageList(): void
    {
        // No inputs and inputs without games both give an empty list.
        $withoutPlayer = $this->service->averageList([]);

        self::assertTrue($withoutPlayer->isEmpty());
        self::assertSame(0, $withoutPlayer->rowCount());
        self::assertSame([], $withoutPlayer->rows);

        $onlyPlayersWithoutGames = $this->service->averageList([
            $this->input('a', 'Anton', netResults: [], handicap: 20),
            $this->input('b', 'Berta', netResults: [], handicap: null),
        ]);

        self::assertTrue($onlyPlayersWithoutGames->isEmpty());
        self::assertSame(0, $onlyPlayersWithoutGames->rowCount());
        self::assertSame(0, $onlyPlayersWithoutGames->total->numberOfGames);
        self::assertSame(0, $onlyPlayersWithoutGames->total->netPins);
        self::assertSame(0.0, $onlyPlayersWithoutGames->total->averageNet);
    }

    /**
     * Validates: R2.15
     *
     * A player without a frozen handicap shows an empty HDC field; their net
     * values stay unchanged and the gross values are dropped.
     */
    public function testPlayerWithoutHandicapShowsEmptyHdcAndUnchangedValues(): void
    {
        $averageList = $this->service->averageList([
            $this->input('a', 'Anton', netResults: [180, 200, 190], handicap: null),
        ]);

        self::assertSame(1, $averageList->rowCount());
        $row = $averageList->rows[0];

        // HDC field empty and gross values dropped (R2.15).
        self::assertNull($row->handicap);
        self::assertNull($row->grossPins);
        self::assertNull($row->averageGross);
        self::assertNull($row->bestSeriesGross);
        self::assertNull($row->bestGameGross);

        // Net values unchanged.
        self::assertSame(3, $row->numberOfGames);
        self::assertSame(570, $row->netPins);
        self::assertSame(190.0, $row->averageNet);
        self::assertSame(570, $row->bestSeriesNet);
        self::assertSame(200, $row->bestGameNet);
    }

    /**
     * Validates: R2.14, R2.15
     *
     * The total row sums games, net pins and gross pins correctly and shows the
     * team averages as numbers with two decimals. Players without a handicap
     * count towards the net sums and the number of games, but not towards the
     * gross base (R2.15).
     */
    public function testTotalRowFormsSumsAndTeamAverages(): void
    {
        $averageList = $this->service->averageList([
            // 2 games, net pins 300, HDC 20 -> gross pins 300 + 20*2 = 340.
            $this->input('a', 'Anton', netResults: [140, 160], handicap: 20),
            // 3 games, net pins 600, HDC 10 -> gross pins 600 + 10*3 = 630.
            $this->input('b', 'Berta', netResults: [200, 200, 200], handicap: 10),
            // 1 game, net pins 100, without handicap -> no gross.
            $this->input('c', 'Cara', netResults: [100], handicap: null),
        ]);

        $total = $averageList->total;

        // Sums across all players (R2.14).
        self::assertSame(6, $total->numberOfGames);
        self::assertSame(1000, $total->netPins);
        // Only players with a handicap contribute to the gross pins (R2.15).
        self::assertSame(970, $total->grossPins);

        // Team net average = 1000 / 6 = 166.67 (R2.14).
        self::assertSame(166.67, $total->averageNet);
        // Team gross average = 970 / 5 (only games with handicap) = 194.00.
        self::assertSame(194.0, $total->averageGross);
    }

    /**
     * Validates: R2.14
     *
     * For a team without any handicap values the gross pins total is null and
     * the team gross average is 0.00, while the net values are aggregated
     * correctly.
     */
    public function testTotalRowWithoutAnyHandicapHasGrossNull(): void
    {
        $averageList = $this->service->averageList([
            $this->input('a', 'Anton', netResults: [150, 150], handicap: null),
        ]);

        $total = $averageList->total;

        self::assertSame(2, $total->numberOfGames);
        self::assertSame(300, $total->netPins);
        self::assertSame(0, $total->grossPins);
        self::assertSame(150.0, $total->averageNet);
        self::assertSame(0.0, $total->averageGross);
    }

    /**
     * Creates an {@see AverageListPlayerInput} for the tests.
     *
     * @param list<int> $netResults
     */
    private function input(
        string $playerId,
        string $playerName,
        array $netResults,
        ?int $handicap,
        Gender $gender = Gender::MALE,
    ): AverageListPlayerInput {
        return new AverageListPlayerInput(
            $playerId,
            $playerName,
            $gender,
            $netResults,
            $handicap,
        );
    }
}
