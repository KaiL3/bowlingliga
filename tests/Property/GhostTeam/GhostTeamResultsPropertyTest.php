<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\GhostTeam;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\GhostTeam\GhostTeamService;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the fixed results of the ghost team.
 *
 * Feature: bowlingliga-website, Property 12: Ghost team results
 *
 * Validates: R20.1, R20.2, R16.10, R16.11, R20.8
 *
 * Domain background: with an odd number of teams the ghost team steps in as
 * opponent. For every target score:
 *   - every net result of a ghost team player equals the target score
 *     (R20.1).
 *   - the corresponding gross result equals the net result, since the ghost
 *     team gets no handicap (R20.2).
 *   - the ghost team lineup comprises exactly 4 positions × 3 games
 *     (R21.7).
 *   - if the team pins are formed from the fixed results, net and gross pins
 *     are equal (R16.10, R16.11, R20.8).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\GhostTeam\GhostTeamService} is tested.
 */
final class GhostTeamResultsPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 12: for every target score (0..300) every net result of a ghost
     * team player equals the target score and every gross equals the net (no
     * handicap); the lineup has exactly 4 positions × 3 games; the team net
     * pins equal the team gross pins.
     *
     * Feature: bowlingliga-website, Property 12: Ghost team results
     *
     * Validates: R20.1, R20.2, R16.10, R16.11, R20.8
     *
     * At least 100 inputs are generated (limitTo(100)); the target score covers
     * the complete valid range 0 to 300.
     */
    public function testGhostTeamResultsAreFixedAndWithoutHandicap(): void
    {
        $service = new GhostTeamService();

        $this
            ->limitTo(100)
            ->forAll(Generators::choose(0, 300))
            ->then(function (int $targetScore) use ($service): void {
                // R20.1 / R20.2: per position and game net = target score,
                // gross = net.
                $results = $service->results($targetScore);

                self::assertCount(
                    GhostTeamService::POSITION_COUNT,
                    $results,
                    'The ghost team must have exactly 4 positions (R21.7).'
                );

                foreach ($results as $result) {
                    for ($game = 1; $game <= GhostTeamService::GAME_COUNT; $game++) {
                        self::assertSame(
                            $targetScore,
                            $result->net($game),
                            'Every net result of a ghost team player must equal the ghost score (R20.1).'
                        );
                        self::assertSame(
                            $result->net($game),
                            $result->gross($game),
                            'The gross of a ghost team player must equal the net (no handicap, R20.2).'
                        );
                    }
                }

                // R21.7: lineup with exactly 4 positions × 3 games,
                // every gross value = target score.
                $lineup = $service->lineup($targetScore);
                self::assertSame(4, $lineup->positionCount(), 'The ghost team lineup must have 4 positions (R21.7).');
                self::assertSame(3, $lineup->gameCount(), 'Every position must have 3 single games (R21.7).');

                for ($position = 1; $position <= 4; $position++) {
                    for ($game = 1; $game <= 3; $game++) {
                        self::assertSame(
                            $targetScore,
                            $lineup->grossOfPosition($position, $game),
                            'Every gross value of the ghost team lineup must equal the ghost score (R20.2, R20.7).'
                        );
                    }
                }

                // R16.10 / R16.11 / R20.8: if the team pins are formed from the fixed
                // results, net and gross pins are equal.
                $netPins  = $service->netPins($targetScore);
                $grossPins = $service->grossPins($targetScore);

                self::assertSame(
                    $netPins,
                    $grossPins,
                    'For the ghost team net pins and gross pins must agree (no handicap, R16.10/16.11/20.8).'
                );

                // Independent oracle: 4 positions × 3 games × target score.
                self::assertSame(
                    4 * 3 * $targetScore,
                    $netPins,
                    'The net pins must equal the sum of all fixed single game results (R20.8).'
                );
            });
    }
}
