<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\SeasonPlanner;

use Bowlingliga\Domain\SeasonPlanner\Matchday;
use Bowlingliga\Domain\SeasonPlanner\SeasonLength;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Domain\SeasonPlanner\Team;
use Bowlingliga\Tests\GermanText;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property test: special days (positionday, lot draw) chosen per matchday do
 * not disturb the round robin.
 *
 * For every number of teams and any placement of special days: the special
 * days have no fixtures, and if the regular matchdays form k complete rounds,
 * every pair of teams meets exactly k times without a distribution warning.
 */
final class SpecialDaysPropertyTest extends TestCase
{
    use TestTrait;

    public function testSpecialDaysAreSkippedByTheRoundRobin(): void
    {
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(2, 9),   // real teams (odd → ghost team)
                Generators::choose(1, 4),   // complete rounds of regular matchdays
                Generators::choose(0, 6),   // number of special days
                Generators::choose(0, 1000) // seed for their placement and type
            )
            ->then(function (int $teamCount, int $rounds, int $specialCount, int $seed): void {
                $teams = [];
                for ($i = 1; $i <= $teamCount; $i++) {
                    $teams[] = new Team((string) $i, 'Team ' . $i);
                }
                $planningTeams = $teamCount + $teamCount % 2;
                $regularDays = $rounds * ($planningTeams - 1);
                $matchdayCount = $regularDays + $specialCount;

                mt_srand($seed);
                $types = [];
                while (count($types) < $specialCount) {
                    $types[mt_rand(1, $matchdayCount)] = mt_rand(0, 1) === 0 ? Matchday::TYPE_POSITIONDAY : Matchday::TYPE_LOT_DRAW;
                }
                for ($number = 1; $number <= $matchdayCount; $number++) {
                    $types[$number] ??= Matchday::TYPE_REGULAR;
                }

                $result = (new SeasonPlanner())->generateSchedule($teams, SeasonLength::fromMatchdayCount($matchdayCount), 0, $types);

                self::assertTrue($result->successful);
                self::assertFalse($result->hasWarnings(), 'Complete rounds over the regular matchdays: no warning.');
                $encounters = [];
                foreach ($result->schedule->matchdays as $matchday) {
                    self::assertSame($types[$matchday->number], $matchday->type);
                    if ($matchday->isSpecialDay()) {
                        self::assertSame([], $matchday->fixtures, 'Special matchdays have no fixtures.');
                        continue;
                    }
                    self::assertCount(intdiv($planningTeams, 2), $matchday->fixtures);
                    foreach ($matchday->fixtures as $fixture) {
                        $ids = [$fixture->home->id, $fixture->away->id];
                        sort($ids);
                        $key = implode('-', $ids);
                        $encounters[$key] = ($encounters[$key] ?? 0) + 1;
                    }
                }

                self::assertCount(intdiv($planningTeams * ($planningTeams - 1), 2), $encounters, 'Every fixture occurs.');
                self::assertSame([$rounds], array_values(array_unique($encounters)), 'Every fixture equally often.');
            });
    }

    public function testIncompleteRoundsGiveTheWarning(): void
    {
        $teams = [new Team('1', 'A'), new Team('2', 'B'), new Team('3', 'C'), new Team('4', 'D')];

        // 7 matchdays, one of them a lot draw → 6 regular = 2 rounds: no warning.
        $complete = (new SeasonPlanner())->generateSchedule($teams, SeasonLength::fromMatchdayCount(7), 0, [4 => Matchday::TYPE_LOT_DRAW]);
        self::assertFalse($complete->hasWarnings());

        // 7 regular matchdays: 2⅓ rounds → warning naming the regular matchdays.
        $incomplete = (new SeasonPlanner())->generateSchedule($teams, SeasonLength::fromMatchdayCount(7), 0);
        self::assertTrue($incomplete->hasWarnings());
        self::assertStringContainsString('Die 7 regulären Spieltage', GermanText::of($incomplete->warnings[0]));

        self::assertFalse((new SeasonPlanner())->generateSchedule($teams, SeasonLength::fromMatchdayCount(3), 0, [2 => 'quatsch'])->successful);
    }
}
