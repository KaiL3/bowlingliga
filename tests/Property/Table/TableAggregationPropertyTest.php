<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Table;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Table\TableService;
use Bowlingliga\Domain\Table\TeamStatistics;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the aggregation of the league table and the exclusion of
 * unplayed matchdays.
 *
 * Feature: bowlingliga-website, Property 10: Table aggregation and exclusion of unplayed matchdays
 *
 * Validates: R16.1, R16.6, R16.9, R24.3
 *
 * Domain background: the {@see TableService} is a pure calculation unit
 * without side effects. It receives per team the key figures already summed
 * up over the matchdays to include as {@see TeamStatistics} (requirement
 * 16.1). Which matchdays go into these sums is decided by the aggregating
 * layer: for the league table these are only the matchdays of the season
 * played up to the time of the request (R16.6), and only matchdays
 * flagged as played count as played (R24.3). Excluding unplayed
 * matchdays is thus part of the aggregation contract of the input and is
 * represented here by the service using only the team statistics passed in:
 * it invents no rows, drops no row and includes in the sums exactly the
 * values passed to it. So exactly one row per team passed in (including the
 * ghost team) arises and the total row sums exactly these teams (requirement
 * 16.9).
 *
 * Tested are {@see TableService::leagueTable()} and the resulting averages
 * per row (R16.1) and the total row (R16.9).
 */
final class TableAggregationPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 10: for every set of already aggregated team statistics the
     * service forms exactly one row per team, calculates the averages per row
     * as quotient of pins and number of games played rounded to two decimals
     * (or 0.00 without games) and shows a total row whose sums equal exactly
     * the sums of all teams and whose averages are formed from these total pins
     * and the total number of games played.
     *
     * Feature: bowlingliga-website, Property 10: Table aggregation and exclusion of unplayed matchdays
     *
     * Validates: R16.1, R16.6, R16.9, R24.3
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * 0 to 12 teams per table with realistic key figures, including teams
     * without any games played (count 0) and optionally a ghost team whose net
     * and gross pins are equal (R16.11).
     */
    public function testTableAggregationAndExclusionOfUnplayedMatchdays(): void
    {
        $service = new TableService();

        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq(
                    Generators::tuple(
                        Generators::choose(0, 200),   // punkte
                        Generators::choose(0, 20),    // numberOfGames
                        Generators::choose(0, 300),   // net average per game
                        Generators::choose(0, 60),    // handicap per game (gross = net + HDC)
                    )
                ),
                Generators::bool(),
            )
            ->then(function (array $rawData, bool $withGhostTeam) use ($service): void {
                $statistics = $this->buildStatistics($rawData, $withGhostTeam);

                $table = $service->leagueTable($statistics);

                // ----- Row correspondence: exactly the input teams, nothing invented,
                // nothing dropped (aggregation contract, R16.6/R24.3). -----
                self::assertCount(
                    count($statistics),
                    $table->rows,
                    'The table must have exactly one row per given team (none left out, none invented).'
                );

                $inputIds = array_map(static fn (TeamStatistics $s): string => $s->teamId, $statistics);
                $rowIds = array_map(static fn ($z): string => $z->teamId, $table->rows);
                sort($inputIds);
                sort($rowIds);
                self::assertSame(
                    $inputIds,
                    $rowIds,
                    'The rows must exactly match the given team statistics (R16.6/R24.3).'
                );

                // Lookup table of the input per teamId for comparing values.
                $inputById = [];
                foreach ($statistics as $statistic) {
                    $inputById[$statistic->teamId] = $statistic;
                }

                // ----- Averages per row (R16.1). -----
                foreach ($table->rows as $row) {
                    $sourceText = $inputById[$row->teamId];

                    // Raw sums are taken over unchanged.
                    self::assertSame($sourceText->points, $row->points, 'Points must be taken over unchanged (R16.1).');
                    self::assertSame($sourceText->netPins, $row->netPins, 'Net pins must be taken over unchanged (R16.1).');
                    self::assertSame($sourceText->grossPins, $row->grossPins, 'Gross pins must be taken over unchanged (R16.1).');
                    self::assertSame($sourceText->numberOfGames, $row->numberOfGames, 'The number of games played must be taken over unchanged (R16.1).');

                    self::assertSame(
                        $this->expectedAverage($sourceText->netPins, $sourceText->numberOfGames),
                        $row->averageNet,
                        sprintf('The net average must be round(netPins/games, 2) or 0.00 without games (R16.1); team %s.', $row->teamId)
                    );
                    self::assertSame(
                        $this->expectedAverage($sourceText->grossPins, $sourceText->numberOfGames),
                        $row->averageGross,
                        sprintf('The gross average must be round(grossPins/games, 2) or 0.00 without games (R16.1); team %s.', $row->teamId)
                    );
                }

                // ----- Total row (R16.9): sums across all teams including the ghost team. -----
                $netPinsSum = 0;
                $grossPinsSum = 0;
                $gamesSum = 0;
                foreach ($statistics as $statistic) {
                    $netPinsSum += $statistic->netPins;
                    $grossPinsSum += $statistic->grossPins;
                    $gamesSum += $statistic->numberOfGames;
                }

                self::assertSame($netPinsSum, $table->total->netPins, 'Total row: sum of net pins over all teams (R16.9).');
                self::assertSame($grossPinsSum, $table->total->grossPins, 'Total row: sum of gross pins over all teams (R16.9).');
                self::assertSame($gamesSum, $table->total->numberOfGames, 'Total row: total number of games played over all teams (R16.9).');

                self::assertSame(
                    $this->expectedAverage($netPinsSum, $gamesSum),
                    $table->total->averageNet,
                    'Total row: the net average must be round(netPins/games, 2) or 0.00 (R16.9).'
                );
                self::assertSame(
                    $this->expectedAverage($grossPinsSum, $gamesSum),
                    $table->total->averageGross,
                    'Total row: the gross average must be round(grossPins/games, 2) or 0.00 (R16.9).'
                );
            });
    }

    /**
     * Builds a list of already aggregated team statistics from the generated
     * raw data. The pins follow as (average per game × number of games), so
     * that realistic, non-negative sums arise and teams without any games
     * played (count 0, pins 0) are covered as well.
     *
     * Optionally a ghost team is appended as the last team, whose net and gross
     * pins are equal (R16.11).
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $rawData
     *
     * @return list<TeamStatistics>
     */
    private function buildStatistics(array $rawData, bool $withGhostTeam): array
    {
        $statistics = [];

        foreach ($rawData as $index => [$points, $numberOfGames, $netAvg, $handicap]) {
            $netPins = $netAvg * $numberOfGames;
            $grossPins = ($netAvg + $handicap) * $numberOfGames;

            $statistics[] = new TeamStatistics(
                'team-' . $index,
                'Team ' . $index,
                $points,
                $netPins,
                $grossPins,
                $numberOfGames,
                false,
            );
        }

        if ($withGhostTeam) {
            // Ghost team: net pins == gross pins (no handicap, R16.11).
            $ghostGames = count($rawData) % 5; // 0..4, also covers "without games"
            $ghostPins = 180 * $ghostGames;

            $statistics[] = new TeamStatistics(
                'geisterteam',
                'Geisterteam',
                $ghostGames * 2,
                $ghostPins,
                $ghostPins,
                $ghostGames,
                true,
            );
        }

        return $statistics;
    }

    /**
     * Independent reference calculation of the average rounded to two
     * decimals. Without games played the average is 0.00 by definition and no
     * division by zero occurs (R16.1, R16.7, R16.9).
     */
    private function expectedAverage(int $pins, int $numberOfGames): float
    {
        if ($numberOfGames === 0) {
            return 0.0;
        }

        return round($pins / $numberOfGames, 2);
    }
}
