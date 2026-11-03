<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Average;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Average\AverageInput;
use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Average\PreviousSeasonRecord;
use PHPUnit\Framework\TestCase;

/**
 * Property test for including the previous season depending on the matchday
 * counter (previous season threshold).
 *
 * Feature: bowlingliga-website, Property 2: Previous season inclusion by matchday threshold
 *
 * Validates: R11.3, R11.4, R11.5, R11.6
 *
 * Domain background: the AverageModule always forms the average from the net
 * games actually played by the player. Including the previous season depends
 * on the matchday counter of the current season:
 *   - Matchday counter <= 5: in addition to the games actually played in the
 *     current season, the games actually played in the previous season are
 *     included (R11.3). If no previous season games were actually
 *     played but a manual previous season record exists, its net pin total and
 *     game count are included instead (R11.4). Without either, the
 *     average is formed from the current season only (R11.6).
 *   - Matchday counter >= 6: only the games actually played in the current
 *     season (R11.5).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\Average\AverageModule::calculateAverage()} is
 * tested.
 */
final class PreviousSeasonInclusionPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 2 (previous season inclusion by matchday threshold): for any
     * combination of current and previous season games and optional previous
     * season records, the calculated average equals exactly the domain
     * inclusion logic across the threshold (5 vs. 6):
     *   - <= 5 with played previous season games: previous season included
     *     (R11.3),
     *   - <= 5 without a played previous season but with a record: record
     *     included instead (R11.4),
     *   - <= 5 without either: current season only (R11.6),
     *   - >= 6: current season only (R11.5).
     *
     * Feature: bowlingliga-website, Property 2: Previous season inclusion by matchday threshold
     *
     * Validates: R11.3, R11.4, R11.5, R11.6
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * both sides of the threshold (matchday counter 1..10, with special
     * attention to the boundary 5/6).
     */
    public function testPreviousSeasonIsIncludedByMatchdayThreshold(): void
    {
        $module = new AverageModule();

        $this
            ->limitTo(100)
            ->forAll(
                // Matchday counter on both sides of the threshold (1..10, boundary 5/6).
                Generators::choose(1, 10),
                // Actually played net results of the current season (0..5 games).
                Generators::seq(Generators::choose(0, 300)),
                // Actually played previous season net results (0..5 games).
                Generators::seq(Generators::choose(0, 300)),
                // Optional previous season record: game count (0 = not includable).
                Generators::choose(0, 10),
                // Optional previous season record: net pin total.
                Generators::choose(0, 3000)
            )
            ->then(function (
                int $matchdayCounter,
                array $currentNet,
                array $previousSeasonNet,
                int $recordGameCount,
                int $recordPins
            ) use ($module): void {
                // Only use the record if the pin total matches the
                // game count (consistency, the numbers carry no meaning).
                $record = new PreviousSeasonRecord($recordGameCount, $recordPins);

                $games = [];
                foreach ($currentNet as $net) {
                    $games[] = new Game($net, false, false, false);
                }
                foreach ($previousSeasonNet as $net) {
                    $games[] = new Game($net, false, false, true);
                }

                $input = new AverageInput($games, $record);

                $result = $module->calculateAverage(1, 1, $matchdayCounter, $input);

                $expected = $this->expectedAverage(
                    $matchdayCounter,
                    $currentNet,
                    $previousSeasonNet,
                    $recordGameCount,
                    $recordPins
                );

                self::assertSame(
                    $expected,
                    $result,
                    sprintf(
                        'The average at matchday counter %d must follow the previous season inclusion '
                        . '(R11.3/11.4/11.5/11.6).',
                        $matchdayCounter
                    )
                );

                // Additional check of the threshold semantics: the same
                // input, but beyond the threshold (>= 6), must never include the
                // previous season. For this we compare with a pure
                // current season calculation.
                $currentOnly = $this->meanOrNull($currentNet);

                if ($matchdayCounter >= 6) {
                    self::assertSame(
                        $currentOnly,
                        $result,
                        'From matchday counter 6 only the current season may count (R11.5).'
                    );
                }

                // <= 5 without any previous season data: identical to the current
                // season alone (R11.6).
                if ($matchdayCounter <= 5 && $previousSeasonNet === [] && $recordGameCount === 0) {
                    self::assertSame(
                        $currentOnly,
                        $result,
                        'Without previous season games and without a record only the current season counts (R11.6).'
                    );
                }
            });
    }

    /**
     * Independent reference implementation (oracle) of the previous season
     * inclusion logic.
     *
     * R11.3: <= 5 → additionally include played previous season games.
     * R11.4: <= 5 without a played previous season but with a record →
     *                   include the record (pin total and game count) instead.
     * R11.5: >= 6 → current season only.
     * R11.6: <= 5 without previous season and without record → current
     *                   season only.
     *
     * @param list<int> $currentNet        Net results of the current season.
     * @param list<int> $previousSeasonNet Net results of played previous season games.
     */
    private function expectedAverage(
        int $matchdayCounter,
        array $currentNet,
        array $previousSeasonNet,
        int $recordGameCount,
        int $recordPins
    ): ?float {
        $sum   = array_sum($currentNet);
        $quantity  = count($currentNet);

        if ($matchdayCounter <= AverageModule::PREVIOUS_SEASON_THRESHOLD) {
            if ($previousSeasonNet !== []) {
                $sum  += array_sum($previousSeasonNet);
                $quantity += count($previousSeasonNet);
            } elseif ($recordGameCount > 0) {
                $sum  += $recordPins;
                $quantity += $recordGameCount;
            }
        }

        if ($quantity === 0) {
            return AverageModule::WITHOUT_AVERAGE;
        }

        return round($sum / $quantity, 2);
    }

    /**
     * Arithmetic mean of the given net results (rounded to two decimals), or
     * null if there are no values.
     *
     * @param list<int> $net
     */
    private function meanOrNull(array $net): ?float
    {
        if ($net === []) {
            return null;
        }

        return round(array_sum($net) / count($net), 2);
    }
}
