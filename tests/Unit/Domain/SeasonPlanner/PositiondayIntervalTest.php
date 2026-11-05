<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\SeasonPlanner;

use Bowlingliga\Domain\SeasonPlanner\Matchday;
use Bowlingliga\Domain\SeasonPlanner\SeasonLength;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Domain\SeasonPlanner\Team;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the configurable positionday interval of the season planner:
 * every n-th matchday is a positionday, 0 means none.
 */
final class PositiondayIntervalTest extends TestCase
{
    /**
     * @return iterable<string, array{int|null, list<int>}>
     */
    public static function intervals(): iterable
    {
        yield 'Standard: jeder zehnte' => [null, [10, 20]];
        yield 'jeder achte' => [8, [8, 16, 24]];
        yield 'keine' => [0, []];
        yield 'größer als die Saison' => [30, []];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('intervals')]
    public function testPositiondaysFollowTheInterval(?int $interval, array $expected): void
    {
        $teams = [new Team('1', 'Adler'), new Team('2', 'Falken'), new Team('3', 'Eulen'), new Team('4', 'Spatzen')];
        $length = SeasonLength::fromMatchdayCount(24);
        $planner = new SeasonPlanner();

        $result = $interval === null ? $planner->generateSchedule($teams, $length) : $planner->generateSchedule($teams, $length, $interval);

        self::assertNotNull($result->schedule);
        $positiondays = array_values(array_map(
            static fn (Matchday $matchday): int => $matchday->number,
            array_filter($result->schedule->matchdays, static fn (Matchday $matchday): bool => $matchday->isPositionday())
        ));
        self::assertSame($expected, $positiondays);
    }

    public function testTypeFromNumber(): void
    {
        self::assertTrue(Matchday::fromNumber(6, [], 3)->isPositionday());
        self::assertFalse(Matchday::fromNumber(7, [], 3)->isPositionday());
        self::assertFalse(Matchday::fromNumber(10, [], 0)->isPositionday());
        self::assertTrue(Matchday::fromNumber(10, [])->isPositionday(), 'Without a value every tenth.');
    }
}
