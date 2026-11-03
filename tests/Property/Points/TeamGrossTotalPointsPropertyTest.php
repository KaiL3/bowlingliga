<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Points;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Points\Lineup;
use Bowlingliga\Domain\Points\BasePointsConfiguration;
use Bowlingliga\Domain\Points\PointsService;
use PHPUnit\Framework\TestCase;

/**
 * Property test for team gross, base points and total points of a matchday.
 *
 * Feature: bowlingliga-website, Property 8: Team gross, base points and total points
 *
 * Validates: R17.2, R17.3, R17.4, R17.9
 *
 * Domain background:
 *   - Team gross of game k = sum of the gross results of all 4 positions of
 *     the lineup in game k (R17.2).
 *   - Base points = per game comparison of the own team gross against the
 *     opposing team gross; win/draw/loss value (R17.3); maximum =
 *     win value × 3 (R17.4).
 *   - Total points = base points + bonus points(gross total) for teams with a
 *     real player; maximum = (win value × 3) + 6 (R17.9). Without a
 *     real player the evening counts as lost → 0 points.
 *
 * The pure calculation service {@see \Bowlingliga\Domain\Points\PointsService}
 * is tested.
 */
final class TeamGrossTotalPointsPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 8: team gross, base points and total points follow the rules of
     * R17.2, R17.3, R17.4 and R17.9 exactly for any two valid lineups
     * and any base points configuration.
     *
     * Feature: bowlingliga-website, Property 8: Team gross, base points and total points
     *
     * Validates: R17.2, R17.3, R17.4, R17.9
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * two complete lineups (4 positions × 3 games, gross 0..300 according to
     * R22.4) and a base points configuration with
     * win ≥ draw ≥ loss ≥ 0.
     */
    public function testTeamGrossBasePointsAndTotalPointsFollowTheRules(): void
    {
        $service = new PointsService();

        $grossGen = Generators::choose(0, 300);
        // Lineup: 4 positions with 3 game gross values each (gross 0..300).
        $lineupGen = Generators::vector(
            4,                                 // 4 Positionen
            Generators::vector(3, $grossGen)  // 3 single games per position
        );

        $this
            ->limitTo(100)
            ->forAll(
                $lineupGen,
                $lineupGen,
                // Grundpunkte-Konfiguration: Sieg ≥ Unentschieden ≥ Niederlage ≥ 0.
                Generators::choose(0, 5), // niederlage
                Generators::choose(0, 5), // unentschieden-Offset
                Generators::choose(0, 5)  // sieg-Offset
            )
            ->then(function (
                array $ownPositions,
                array $opponentPositions,
                int $loss,
                int $drawOffset,
                int $winOffset
            ) use ($service): void {
                $draw = $loss + $drawOffset;
                $win = $draw + $winOffset;
                $config = new BasePointsConfiguration($win, $draw, $loss);

                $own = new Lineup($ownPositions, true);
                $opponent = new Lineup($opponentPositions, true);

                // --- R17.2: team gross per game = sum of the 4 positions ---
                for ($k = 1; $k <= 3; $k++) {
                    $expectedTeamGross = 0;
                    foreach ($ownPositions as $positionGames) {
                        $expectedTeamGross += $positionGames[$k - 1];
                    }
                    self::assertSame(
                        $expectedTeamGross,
                        $service->teamGrossGame($own, $k),
                        sprintf('The team gross of single game %d must equal the sum of the 4 positions (R17.2).', $k)
                    );
                }

                // --- R17.3, R17.4: base points via an independent oracle ---
                $expectedBasePoints = 0;
                for ($k = 1; $k <= 3; $k++) {
                    $ownGross = 0;
                    $opponentGross = 0;
                    foreach ($ownPositions as $positionGames) {
                        $ownGross += $positionGames[$k - 1];
                    }
                    foreach ($opponentPositions as $positionGames) {
                        $opponentGross += $positionGames[$k - 1];
                    }

                    if ($ownGross > $opponentGross) {
                        $expectedBasePoints += $win;
                    } elseif ($ownGross === $opponentGross) {
                        $expectedBasePoints += $draw;
                    } else {
                        $expectedBasePoints += $loss;
                    }
                }

                $basePoints = $service->basePoints($own, $opponent, $config);
                self::assertSame(
                    $expectedBasePoints,
                    $basePoints,
                    'Base points must match the win/draw/loss oracle per single game (R17.3).'
                );

                // Grundpunkte-Wertebereich [niederlage*3, sieg*3]; Maximum = sieg*3 (R17.4).
                self::assertGreaterThanOrEqual(
                    $loss * 3,
                    $basePoints,
                    'Base points must not fall below the minimum base points (loss×3) (R17.4).'
                );
                self::assertLessThanOrEqual(
                    $service->maximumBasePoints($config),
                    $basePoints,
                    'Base points must not exceed the maximum base points (win×3) (R17.4).'
                );
                self::assertSame(
                    $win * 3,
                    $service->maximumBasePoints($config),
                    'The maximum base points must be win value × 3 (R17.4).'
                );

                // --- R17.9: total points = base points + bonus points(gross total) ---
                $grossTotal = $service->grossTotal($own);
                $expectedTotalPoints = $basePoints + $service->bonusPoints($grossTotal);
                $totalPoints = $service->totalPoints($own, $opponent, $config);

                self::assertSame(
                    $expectedTotalPoints,
                    $totalPoints,
                    'Total points must equal base points plus bonus points (R17.9).'
                );

                // Gesamtpunkte-Wertebereich [0, maximumTotalPoints] (R17.9).
                self::assertGreaterThanOrEqual(
                    0,
                    $totalPoints,
                    'Total points must not be negative (R17.9).'
                );
                self::assertLessThanOrEqual(
                    $service->maximumTotalPoints($config),
                    $totalPoints,
                    'Total points must not exceed the maximum total points (win×3 + 6) (R17.9).'
                );
                self::assertSame(
                    $win * 3 + 6,
                    $service->maximumTotalPoints($config),
                    'The maximum total points must be (win value × 3) + 6 (R17.9).'
                );

                // --- R21.6, R27.3: without a real player 0 points ---
                $ownWithoutPlayer = new Lineup($ownPositions, false);
                self::assertSame(
                    0,
                    $service->totalPoints($ownWithoutPlayer, $opponent, $config),
                    'Without a real player the evening must count with 0 points (R17.9 with R21.6/27.3).'
                );
            });
    }
}
