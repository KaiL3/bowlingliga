<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Table;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Table\TableService;
use Bowlingliga\Domain\Table\TableRow;
use Bowlingliga\Domain\Table\TeamStatistics;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the sorting and the shared rank of the league table.
 *
 * Feature: bowlingliga-website, Property 9: Sorting and shared rank of the league table
 *
 * Validates: R16.2, R16.3, R16.4, R16.5, R16.8
 *
 * Domain background: the league table sorts the team statistics descending by
 * the lexicographic criteria points (R16.2), gross of the games played
 * (R16.3) and net pins (R16.4). The rank starts at 1 for the first placed
 * team (R16.8). If several teams match in all three criteria, they get the
 * same rank; the following ranks are skipped by the number of tied teams (e.g.
 * 1, 1, 3) (R16.5).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\Table\TableService::leagueTable()} is tested.
 */
final class LeagueTableSortingPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 9: for any list of team statistics the rows of the league table
     * are ordered descending lexicographically by (points, gross pins, net
     * pins) (R16.2–16.4), the rank starts at 1 (R16.8), tied teams share the
     * same rank and the following ranks skip the number of tied teams (R16.5),
     * and the number of rows equals the number of input teams.
     *
     * Feature: bowlingliga-website, Property 9: Sorting and shared rank of the league table
     *
     * Validates: R16.2, R16.3, R16.4, R16.5, R16.8
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * lists with 1 to 12 teams with unique team ids and non-negative key
     * figures from a deliberately small value range, so that ties and shared
     * ranks occur regularly.
     */
    public function testLeagueTableIsSortedAndAssignsSharedRank(): void
    {
        $service = new TableService();

        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq(
                    Generators::tuple(
                        Generators::choose(0, 20),  // punkte
                        Generators::choose(0, 20),  // grossPins
                        Generators::choose(0, 20),  // netPins
                        Generators::choose(0, 30)   // numberOfGames
                    )
                )
            )
            ->then(function (array $rawTeams) use ($service): void {
                $teamStatistics = $this->buildTeamStatistics($rawTeams);
                $teamCount = count($teamStatistics);

                $rows = $service->leagueTable($teamStatistics)->rows;

                // The number of rows equals the number of input teams.
                self::assertCount(
                    $teamCount,
                    $rows,
                    'The league table must have exactly one row per input team.'
                );

                // R16.2–16.4: descending lexicographically by (points, grossPins, netPins).
                foreach ($this->consecutivePairs($rows) as [$prior, $current]) {
                    self::assertGreaterThanOrEqual(
                        0,
                        $this->compareLexicographically($prior, $current),
                        sprintf(
                            'The rows must be sorted descending by points, gross pins, net pins '
                            . '(R16.2–16.4); violated at (%d,%d,%d) before (%d,%d,%d).',
                            $prior->points,
                            $prior->grossPins,
                            $prior->netPins,
                            $current->points,
                            $current->grossPins,
                            $current->netPins
                        )
                    );
                }

                if ($teamCount === 0) {
                    return;
                }

                // R16.8: the rank starts at 1 for the first placed team.
                self::assertSame(
                    1,
                    $rows[0]->rank,
                    'The rank of the first row must be 1 (R16.8).'
                );

                // R16.5: shared rank on a tie, the following ranks skip
                // the number of tied teams.
                foreach ($rows as $position => $row) {
                    if ($position === 0) {
                        continue;
                    }

                    $prior = $rows[$position - 1];

                    if ($this->areEqualRank($prior, $row)) {
                        self::assertSame(
                            $prior->rank,
                            $row->rank,
                            'Teams that tie must get the same rank (R16.5).'
                        );
                    } else {
                        // The next different rank is the 1-based position,
                        // which skips the ranks in between.
                        self::assertSame(
                            $position + 1,
                            $row->rank,
                            sprintf(
                                'After %d preceding teams the next different rank must be %d (R16.5).',
                                $position,
                                $position + 1
                            )
                        );
                    }
                }
            });
    }

    /**
     * Creates a list of {@see TeamStatistics} with unique team ids and
     * non-negative key figures from the generated raw tuples.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rawTeams
     *
     * @return list<TeamStatistics>
     */
    private function buildTeamStatistics(array $rawTeams): array
    {
        $statistics = [];

        foreach ($rawTeams as $index => [$points, $grossPins, $netPins, $numberOfGames]) {
            $statistics[] = new TeamStatistics(
                'team-' . $index,
                'Team ' . $index,
                $points,
                $netPins,
                $grossPins,
                $numberOfGames
            );
        }

        return $statistics;
    }

    /**
     * Returns all consecutive row pairs (previous, current).
     *
     * @param list<TableRow> $rows
     *
     * @return list<array{0:TableRow,1:TableRow}>
     */
    private function consecutivePairs(array $rows): array
    {
        $pairs = [];

        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $pairs[] = [$rows[$i - 1], $rows[$i]];
        }

        return $pairs;
    }

    /**
     * Compares two rows lexicographically by (points, gross pins, net pins).
     * Result > 0 if $a may come before $b, 0 on a tie, < 0 on a sort
     * violation.
     */
    private function compareLexicographically(TableRow $a, TableRow $b): int
    {
        return ($a->points <=> $b->points)
            ?: (($a->grossPins <=> $b->grossPins)
                ?: ($a->netPins <=> $b->netPins));
    }

    /**
     * Checks whether two rows match in all sort criteria (R16.5).
     */
    private function areEqualRank(TableRow $a, TableRow $b): bool
    {
        return $a->points === $b->points
            && $a->grossPins === $b->grossPins
            && $a->netPins === $b->netPins;
    }
}
