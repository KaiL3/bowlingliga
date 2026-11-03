<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\BlindPlayer;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\FilledLineup;
use Bowlingliga\Domain\LineupPosition;
use Bowlingliga\Domain\BlindPlayerService;
use Bowlingliga\Domain\RosterPlayer;
use PHPUnit\Framework\TestCase;

/**
 * Property test for filling a team lineup with blind players up to exactly 4
 * positions.
 *
 * Feature: bowlingliga-website, Property 16: Blind player filling of the lineup
 *
 * Validates: R27.1, R27.2, R27.3, R21.6
 *
 * Domain background: if a real team fields fewer than 4 real players on an
 * evening, the system fills the missing positions with blind players up to
 * exactly 4 positions (R27.1). At most 3 blind players are used
 * and at least 1 real player is kept (R27.2). If the team fields no
 * real player at all, the evening counts as lost, the team gets 0 points and
 * no blind player is used (R27.3, R21.6).
 *
 * The service {@see \Bowlingliga\Domain\BlindPlayerService::fillLineup()}
 * takes over the real player ids unchanged. Rejecting more than 4 real players
 * is a matter of the application layer (task 22.1, R21.4) and not
 * of this pure calculation service; the input range of this property is
 * therefore deliberately limited to 0..4 real player ids.
 *
 * The pure, side-effect-free calculation service is tested.
 */
final class LineupFillingPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 16 (filling without a real player): if the team fields no real
     * player at all, the evening counts as lost, the team gets 0 points and no
     * blind player is used.
     *
     * Feature: bowlingliga-website, Property 16: Blind player filling of the lineup
     *
     * Validates: R27.3, R21.6
     *
     * At least 100 inputs are generated (limitTo(100)); the roster contains any
     * number of candidates, mostly not lined up regularly, to prove that no
     * blind player is used despite available candidates.
     */
    public function testWithoutRealPlayersLostZeroPointsNoBlindPlayers(): void
    {
        $service = new BlindPlayerService();

        $this
            ->limitTo(100)
            ->forAll($this->rosterGenerator())
            ->then(function (array $roster) use ($service): void {
                $result = $service->fillLineup([], $roster);

                self::assertInstanceOf(FilledLineup::class, $result);
                self::assertTrue(
                    $result->lost,
                    'Without a real player the evening must count as lost (R27.3, R21.6).'
                );
                self::assertSame(
                    0,
                    $result->points,
                    'Without a real player the team must get 0 points (R27.3, R21.6).'
                );
                self::assertSame(
                    0,
                    $result->blindPlayerCount(),
                    'Without a real player no blind player may be set (R27.3, R21.6).'
                );
                self::assertSame(
                    0,
                    $result->realPlayerCount(),
                    'Without a real player there must be no real position (R27.3).'
                );
                self::assertSame(
                    [],
                    $result->positions,
                    'Without a real player no lineup is kept (R27.3, R21.6).'
                );
            });
    }

    /**
     * Property 16 (filling with 1..4 real players): with at least one real
     * player the lineup has exactly 4 positions; the number of blind players is
     * exactly 4 − number of real players, at most 3 blind players are used and
     * at least 1 real player is kept. The evening does not count as lost.
     *
     * Feature: bowlingliga-website, Property 16: Blind player filling of the lineup
     *
     * Validates: R27.1, R27.2
     *
     * At least 100 inputs are generated (limitTo(100)); the number of real
     * player ids deliberately covers the full allowed range 1..4.
     */
    public function testWithRealPlayersExactlyFourPositionsFilledCorrectly(): void
    {
        $service = new BlindPlayerService();

        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(1, 4),
                $this->rosterGenerator()
            )
            ->then(function (int $realCount, array $roster) use ($service): void {
                $realPlayerIds = $this->realPlayerIds($realCount);

                $result = $service->fillLineup($realPlayerIds, $roster);

                self::assertInstanceOf(FilledLineup::class, $result);

                self::assertFalse(
                    $result->lost,
                    sprintf('Bei %d echten Spielern darf der Spielabend nicht als verloren gelten (R27.1).', $realCount)
                );

                // Exactly 4 positions in total (R27.1).
                self::assertCount(
                    4,
                    $result->positions,
                    sprintf('The lineup must have exactly 4 positions (R27.1); real=%d.', $realCount)
                );

                $realPositionCount = $result->realPlayerCount();
                $blindCount = $result->blindPlayerCount();

                // All real player ids are taken over unchanged as real positions.
                self::assertSame(
                    $realCount,
                    $realPositionCount,
                    sprintf('All %d real players must appear as real positions (R27.1).', $realCount)
                );

                // Blind count = 4 − min(real, 4); since real is 1..4 here, that is 4 − real.
                self::assertSame(
                    4 - min($realCount, 4),
                    $blindCount,
                    sprintf('The number of blind players must be 4 − real (R27.1); real=%d.', $realCount)
                );

                // At most 3 blind players, at least 1 real player (R27.2).
                self::assertLessThanOrEqual(
                    3,
                    $blindCount,
                    'At most 3 blind players may be used (R27.2).'
                );
                self::assertGreaterThanOrEqual(
                    1,
                    $realPositionCount,
                    'At least 1 real player must take part (R27.2).'
                );

                // realPlayerCount must not exceed 4 (R27.1).
                self::assertLessThanOrEqual(
                    4,
                    $realPositionCount,
                    'At most 4 real players may be listed (R27.1).'
                );

                // The sum of real + blind is exactly 4 (R27.1).
                self::assertSame(
                    4,
                    $realPositionCount + $blindCount,
                    'Real and blind positions must add up to exactly 4 (R27.1).'
                );

                // Position numbers are 1..4 without gaps (R27.1).
                $positionNumbers = array_map(
                    static fn (LineupPosition $p): int => $p->position,
                    $result->positions
                );
                self::assertSame(
                    [1, 2, 3, 4],
                    $positionNumbers,
                    'The 4 positions must be numbered 1..4 without gaps (R27.1).'
                );
            });
    }

    /**
     * Generator for the real player ids of a lineup.
     *
     * The concrete ids do not matter for the filling logic; unique positive
     * values are enough. The ids 1..$quantity are used.
     *
     * @return list<int>
     */
    private function realPlayerIds(int $quantity): array
    {
        return range(1, $quantity);
    }

    /**
     * Generator for a team roster with mostly candidates not lined up
     * regularly, so that a blind player can usually be determined.
     *
     * Generates 0..8 roster players with random games played and a random
     * lineup status. The case of an empty roster or a roster without available
     * candidates is deliberately included, to prove that the number of
     * positions stays exactly 4 even then.
     *
     * @return \Eris\Generator<list<RosterPlayer>>
     */
    private function rosterGenerator(): \Eris\Generator
    {
        return Generators::bind(
            Generators::choose(0, 8),
            static fn (int $quantity): \Eris\Generator => Generators::map(
                static function (array $rows) use ($quantity): array {
                    if ($quantity === 0) {
                        return [];
                    }

                    $roster = [];
                    foreach ($rows as $index => $row) {
                        [$idBase, $gamesPlayed, $regular] = $row;
                        $roster[] = new RosterPlayer(
                            $idBase + $index,
                            $gamesPlayed,
                            (bool) $regular
                        );
                    }

                    return $roster;
                },
                Generators::tuple(
                    ...array_fill(
                        0,
                        max($quantity, 1),
                        Generators::tuple(
                            Generators::choose(1000, 1099),
                            Generators::choose(0, 30),
                            Generators::elements(true, false, false)
                        )
                    )
                )
            )
        );
    }
}
