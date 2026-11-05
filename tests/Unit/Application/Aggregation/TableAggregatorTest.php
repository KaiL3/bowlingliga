<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Aggregation;

use Bowlingliga\Application\Aggregation\FixtureRawData;
use Bowlingliga\Application\Aggregation\FixtureTeamRawData;
use Bowlingliga\Application\Aggregation\SheetRowResults;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Domain\Points\BasePointsConfiguration;
use Bowlingliga\Domain\Points\BonusPointsScale;
use Bowlingliga\Domain\Points\PointsRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see TableAggregator} (option A, step 2).
 *
 * Validates: R16.1, R16.6, R16.7, R16.10, R17, R20.2, R24.3
 *
 * Checks the points/pins aggregation per team, teams without games appearing
 * with zero values (R16.7), the ghost team row (R16.10/R20.2) and the
 * matchday table. Points, table and aggregation logic are used for real; the
 * raw data access is an in-memory fake.
 */
final class TableAggregatorTest extends TestCase
{
    /**
     * Builds a team with 4 rows, all with the same three net values and the
     * same handicap.
     *
     * @param array{0: int, 1: int, 2: int} $net
     */
    private function team(int $id, string $name, array $net, int $handicap, bool $ghosts = false): FixtureTeamRawData
    {
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = new SheetRowResults([1 => $net[0], 2 => $net[1], 3 => $net[2]], $handicap);
        }

        return new FixtureTeamRawData($id, $name, $ghosts, true, $rows);
    }

    public function testPointsAndPinsAreAggregated(): void
    {
        // Home: net per game 150, HDC 0 -> gross 150. Team gross per game = 4*150 = 600.
        // Away: net per game 140, HDC 0 -> team gross per game = 560.
        // Home wins all 3 games -> base points 3*2 = 6.
        // Gross total home = 3*600 = 1800 (<=2100) -> 1 bonus point. Total home = 7.
        $home = $this->team(101, 'Alpha', [150, 150, 150], 0);
        $away = $this->team(102, 'Beta', [140, 140, 140], 0);

        $reader = new FixedTableRawDataReader(
            seasonFixtures: [new FixtureRawData($home, $away)],
            teams: [101 => 'Alpha', 102 => 'Beta'],
        );

        $table = (new TableAggregator($reader))->leagueTable(1);

        // Alpha is ranked 1.
        $alpha = $table->rows[0];
        self::assertSame('Alpha', $alpha->teamName);
        self::assertSame(7, $alpha->points, 'Grundpunkte 6 + 1 Zusatzpunkt (R17).');
        // Net pins Alpha = 4 positions * 3 games * 150 = 1800.
        self::assertSame(1800, $alpha->netPins);
        self::assertSame(1800, $alpha->grossPins, 'With HDC 0 net and gross agree.');
        self::assertSame(12, $alpha->numberOfGames, '4 Positionen * 3 Einzelspiele = 12.');
    }

    /**
     * R17.11: the points rules of the season apply – here 3/1/0 and at most
     * 2 bonus points from 1500 pins on.
     */
    public function testPointsRulesOfTheSeasonApply(): void
    {
        $home = $this->team(101, 'Alpha', [150, 150, 150], 0); // gross total 1800
        $away = $this->team(102, 'Beta', [140, 140, 140], 0);  // gross total 1680
        $rules = new PointsRules(new BasePointsConfiguration(3, 1, 0), new BonusPointsScale(1500, 200, 2));

        $reader = new FixedTableRawDataReader(
            seasonFixtures: [new FixtureRawData($home, $away)],
            matchdayFixtures: [new FixtureRawData($home, $away)],
            teams: [101 => 'Alpha', 102 => 'Beta'],
            rules: $rules,
        );
        $aggregator = new TableAggregator($reader);

        foreach ([$aggregator->leagueTable(1), $aggregator->matchdayTable(1)] as $table) {
            self::assertSame(3 * 3 + 2, $table->rows[0]->points, 'Three wins of 3 plus 2 bonus points.');
            self::assertSame(0 + 2, $table->rows[1]->points, 'Three losses plus 2 bonus points.');
        }
    }

    public function testTeamWithoutGamesAppearsWithZeroValues(): void
    {
        // Only Alpha and Beta play; Gamma takes part but does not play.
        $home = $this->team(101, 'Alpha', [150, 150, 150], 0);
        $away = $this->team(102, 'Beta', [140, 140, 140], 0);

        $reader = new FixedTableRawDataReader(
            seasonFixtures: [new FixtureRawData($home, $away)],
            teams: [101 => 'Alpha', 102 => 'Beta', 103 => 'Gamma'],
        );

        $table = (new TableAggregator($reader))->leagueTable(1);

        self::assertCount(3, $table->rows, 'Teams without games appear as well (R16.7).');
        // Gamma is last with 0 points / 0 games.
        $gamma = $table->rows[2];
        self::assertSame('Gamma', $gamma->teamName);
        self::assertSame(0, $gamma->points);
        self::assertSame(0, $gamma->numberOfGames);
    }

    public function testGhostTeamGetsOwnRow(): void
    {
        $home = $this->team(101, 'Alpha', [150, 150, 150], 0);
        $ghosts = $this->team(0, 'Geisterteam', [190, 190, 190], 0, ghosts: true);

        $reader = new FixedTableRawDataReader(
            seasonFixtures: [new FixtureRawData($home, $ghosts)],
            teams: [101 => 'Alpha'],
        );

        $table = (new TableAggregator($reader))->leagueTable(1);

        $ghostRow = null;
        foreach ($table->rows as $row) {
            if ($row->isGhostTeam) {
                $ghostRow = $row;
            }
        }

        self::assertNotNull($ghostRow, 'The ghost team gets a row of its own (R16.10).');
        self::assertSame($ghostRow->netPins, $ghostRow->grossPins, 'Ghost team without handicap: net = gross (R20.2).');
    }

    public function testMatchdayTableUsesOnlyTheMatchday(): void
    {
        $home = $this->team(101, 'Alpha', [150, 150, 150], 0);
        $away = $this->team(102, 'Beta', [140, 140, 140], 0);

        $reader = new FixedTableRawDataReader(
            matchdayFixtures: [new FixtureRawData($home, $away)],
        );

        $table = (new TableAggregator($reader))->matchdayTable(5);

        self::assertCount(2, $table->rows);
        self::assertSame('Alpha', $table->rows[0]->teamName);
    }

    public function testGrossCalculationWithHandicap(): void
    {
        // Home: net 150, HDC 10 -> gross 160 per game. Team gross per game = 640.
        $home = $this->team(101, 'Alpha', [150, 150, 150], 10);
        $away = $this->team(102, 'Beta', [140, 140, 140], 0);

        $reader = new FixedTableRawDataReader(
            seasonFixtures: [new FixtureRawData($home, $away)],
            teams: [101 => 'Alpha', 102 => 'Beta'],
        );

        $table = (new TableAggregator($reader))->leagueTable(1);

        $alpha = $table->rows[0];
        // Gross pins Alpha = net pins (1800) + HDC 10 * 12 games = 1920.
        self::assertSame(1800, $alpha->netPins);
        self::assertSame(1920, $alpha->grossPins, 'Brutto = Netto + HDC je Spiel (R17.2).');
    }
}
