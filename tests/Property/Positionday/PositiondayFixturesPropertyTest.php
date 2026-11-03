<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Positionday;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Positionday\PositiondayService;
use Bowlingliga\Domain\Table\TableService;
use Bowlingliga\Domain\Table\TeamStatistics;
use PHPUnit\Framework\TestCase;

/**
 * Property test for forming the positionday fixtures from the standings.
 *
 * Feature: bowlingliga-website, Property 20: Positionday fixtures from the standings
 *
 * Validates: R31.2, R31.3
 *
 * Domain background: the fixtures of a positionday are formed from the current
 * state of the league table as soon as all previous matchdays have the
 * completion status "vollständig". The first placed team is put against the
 * second, the third against the fourth and so on (R31.2). The
 * placement follows solely the sort order of the league table according to
 * R16 – points, then gross of the games played, then net pins
 * (R31.3).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\Positionday\PositiondayService::formPositiondayFixtures()}
 * is tested.
 */
final class PositiondayFixturesPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 20 (ready): for every random ranking of team statistics with
     * "all previous matchdays complete" = true the service pairs adjacent ranks
     * of the league table (1v2, 3v4, ...) in exactly the sort order of the
     * table service (R31.2, R31.3).
     *
     * Feature: bowlingliga-website, Property 20: Positionday fixtures from the standings
     *
     * Validates: R31.2, R31.3
     *
     * At least 100 inputs are generated (limitTo(100)).
     */
    public function testFixturesFollowTheLeagueTableOrder(): void
    {
        $service = new PositiondayService();
        $tableService = new TableService();

        $this
            ->limitTo(100)
            ->forAll($this->standingsGenerator())
            ->then(function (array $rawStatistics) use ($service, $tableService): void {
                $standings = $this->buildTeamStatistics($rawStatistics);

                $result = $service->formPositiondayFixtures(true, $standings);

                self::assertTrue(
                    $result->isReady(),
                    'With complete previous matchdays the fixtures must be fixed (R31.2).'
                );
                self::assertNull($result->note, 'A ready result carries no notice (R31.2).');

                // Ranking determined independently, sorted by the league table criteria,
                // as oracle (R16 via the table service).
                // Because of shared ranks (R16.5) a rank can repeat;
                // what matters for the fixture order is the table position, so the
                // rank expectation is taken from the table rows themselves.
                $expectedOrder = [];
                $expectedRank = [];
                foreach ($tableService->leagueTable($standings)->rows as $row) {
                    $expectedOrder[] = $row->teamId;
                    $expectedRank[] = $row->rank;
                }

                $quantity = count($expectedOrder);

                // With an even number of teams n/2 pairs arise; with an odd number of teams
                // the ghost team steps in according to R20 as opponent of the
                // last placed team, so that ceil(n/2) pairs arise.
                $isOdd = ($quantity % 2) === 1;
                $expectedPairs = intdiv($quantity, 2) + ($isOdd ? 1 : 0);

                self::assertCount(
                    $expectedPairs,
                    $result->fixtures,
                    'There must be half as many fixtures as teams (with an odd number including the ghost team fixture) (R31.2).'
                );

                // Pairwise assignment of the real ranks: rank1 vs rank2, rank3 vs rank4, ...
                $fullPairs = intdiv($quantity, 2);
                foreach ($result->fixtures as $index => $fixture) {
                    if ($index >= $fullPairs) {
                        // Added ghost team fixture of the last placed team
                        // with an odd number of teams (R20).
                        self::assertSame(
                            $expectedOrder[$quantity - 1],
                            $fixture->homeTeamId,
                            'With an odd number of teams the last placed team plays the ghost team (R20, R31.2).'
                        );
                        self::assertTrue($fixture->awayIsGhostTeam, 'With an odd number of teams the added opponent is the ghost team (R20).');
                        continue;
                    }

                    $homePosition = $index * 2;
                    $awayPosition = $homePosition + 1;

                    self::assertSame(
                        $expectedOrder[$homePosition],
                        $fixture->homeTeamId,
                        sprintf('The home team of fixture %d must be table position %d (R31.2, R31.3).', $index, $homePosition + 1)
                    );
                    self::assertSame(
                        $expectedOrder[$awayPosition],
                        $fixture->awayTeamId,
                        sprintf('The away team of fixture %d must be table position %d (R31.2, R31.3).', $index, $awayPosition + 1)
                    );

                    // The fixture takes the ranks of the league table at the
                    // adjacent table positions unchanged (R31.3); because of
                    // shared ranks (R16.5) they can also be equal.
                    self::assertSame($expectedRank[$homePosition], $fixture->homeRank, 'The home rank must equal the table rank (R31.3).');
                    self::assertSame($expectedRank[$awayPosition], $fixture->awayRank, 'The away rank must equal the table rank (R31.3).');
                }
            });
    }

    /**
     * Property 20 (not ready): if "all previous matchdays complete" = false,
     * the service fixes no fixtures but returns a result with a note
     * (R31.4). This limit of the formation secures the fixture
     * logic required in R31.2, R31.3.
     *
     * Feature: bowlingliga-website, Property 20: Positionday fixtures from the standings
     *
     * Validates: R31.2, R31.3
     *
     * At least 100 inputs are generated (limitTo(100)).
     */
    public function testNoFixturesWhenPreviousMatchdaysIncomplete(): void
    {
        $service = new PositiondayService();

        $this
            ->limitTo(100)
            ->forAll($this->standingsGenerator())
            ->then(function (array $rawStatistics) use ($service): void {
                $standings = $this->buildTeamStatistics($rawStatistics);

                $result = $service->formPositiondayFixtures(false, $standings);

                self::assertFalse(
                    $result->isReady(),
                    'With incomplete previous matchdays the fixtures must not be fixed (R31.4).'
                );
                self::assertSame([], $result->fixtures, 'No fixtures may be formed (R31.4).');
                self::assertNotNull($result->note, 'A result that is not ready must carry a notice (R31.4).');
            });
    }

    /**
     * Generator for a random set of 0 to 12 teams, each with the four raw sums
     * relevant for sorting (points, net pins, gross pins, number of games).
     * The value range is deliberately small, so that ties in points (and thus
     * shared ranks, R16.5) occur with realistic frequency.
     *
     * @return \Eris\Generator
     */
    private function standingsGenerator()
    {
        $teamGenerator = Generators::tuple(
            Generators::choose(0, 40),   // punkte
            Generators::choose(0, 6000), // netPins
            Generators::choose(0, 6000), // grossPins
            Generators::choose(0, 30)    // numberOfGames
        );

        return Generators::seq($teamGenerator);
    }

    /**
     * Builds valid {@see TeamStatistics} objects with unique team ids from the
     * generated raw tuples. The team id only serves unique attribution; the
     * input order does not matter for the result, since the service sorts via
     * the table service.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $rawStatistics
     *
     * @return list<TeamStatistics>
     */
    private function buildTeamStatistics(array $rawStatistics): array
    {
        $statistics = [];

        foreach ($rawStatistics as $index => [$points, $netPins, $grossPins, $numberOfGames]) {
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
}
