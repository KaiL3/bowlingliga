<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Average;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Average\AverageInput;
use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the average from actually played net games.
 *
 * Feature: bowlingliga-website, Property 1: Average from actually played net games
 *
 * Validates: R11.1, R11.2
 *
 * Domain background: the AverageModule only includes the net games actually
 * played by the player (R11.1): games marked blind (R27) and ghost
 * team results (R20) are ignored. The average is the arithmetic mean of these
 * included net results, rounded to two decimals (R11.2).
 *
 * To keep the previous season special rules (R11.3/11.4) out of the picture,
 * a matchday counter >= 6 is used throughout. In this range, according to
 * R11.5, only the current season counts, so that the expectation
 * can be derived solely from the games actually played in the current season.
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\Average\AverageModule::calculateAverage()} is
 * tested.
 */
final class AverageActuallyPlayedPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 1 (actually played net games): for every random mix of actually
     * played, blind and ghost team games of the current season, the calculated
     * average equals exactly the arithmetic mean, rounded to two decimals, of
     * only the actually played net results (R11.1, R11.2). Blind and
     * ghost team games do not change the result (metamorphic property: adding
     * such games leaves the average unchanged).
     *
     * Feature: bowlingliga-website, Property 1: Average from actually played net games
     *
     * Validates: R11.1, R11.2
     *
     * At least 100 inputs are generated (limitTo(100)). The matchday counter is
     * limited to >= 6 to isolate the current season.
     */
    public function testAverageIsFormedOnlyFromActuallyPlayedNetGames(): void
    {
        $module = new AverageModule();

        // Generator for a single game of the current season with
        // a random net result (0..300) and a random marking as
        // real / blind / ghost team. Previous season stays false (current season).
        $gameGen = Generators::associative([
            'net' => Generators::choose(0, 300),
            // 0 = real, 1 = blind, 2 = ghost team
            'kind'   => Generators::choose(0, 2),
        ]);

        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq($gameGen),
                Generators::choose(6, 40)
            )
            ->then(function (array $rawGames, int $matchdayCounter) use ($module): void {
                $games = [];
                $realNet   = [];

                foreach ($rawGames as $raw) {
                    $blind       = $raw['kind'] === 1;
                    $ghostTeam = $raw['kind'] === 2;

                    $games[] = new Game(
                        netResult: $raw['net'],
                        blind: $blind,
                        ghostTeam: $ghostTeam,
                        previousSeason: false,
                    );

                    if (!$blind && !$ghostTeam) {
                        $realNet[] = $raw['net'];
                    }
                }

                $input = new AverageInput($games);
                $result = $module->calculateAverage(1, 1, $matchdayCounter, $input);

                if ($realNet === []) {
                    // Without an actually played game there is no includable game:
                    // "without average" (R11.7).
                    self::assertNull(
                        $result,
                        'Without a net game actually played there must be no average.'
                    );

                    return;
                }

                // Independent oracle: arithmetic mean of only the actually
                // played net results, rounded to two decimals
                // (R11.1, R11.2).
                $expected = round(array_sum($realNet) / count($realNet), 2);

                self::assertSame(
                    $expected,
                    $result,
                    sprintf(
                        'The average must equal the rounded mean of the %d net games actually played (R11.1/11.2).',
                        count($realNet)
                    )
                );

                // Metamorphic property: if additional blind and
                // ghost team games with any net result are added, the
                // average stays unchanged (R11.1).
                $withExtra = $games;
                $withExtra[] = new Game(netResult: 300, blind: true);
                $withExtra[] = new Game(netResult: 0, ghostTeam: true);
                $withExtra[] = new Game(netResult: 150, blind: true, ghostTeam: true);

                $resultWithExtra = $module->calculateAverage(
                    1,
                    1,
                    $matchdayCounter,
                    new AverageInput($withExtra)
                );

                self::assertSame(
                    $result,
                    $resultWithExtra,
                    'Blind and ghost team games must not change the average (R11.1).'
                );
            });
    }
}
