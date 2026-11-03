<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\GhostTeam;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Average\AverageInput;
use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Honours\HonoursModule;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\Honours\GameResultInput;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the metamorphic exclusion of blind and ghost team results.
 *
 * Feature: bowlingliga-website, Property 11: Metamorphic exclusion of blind and ghost team results
 *
 * Validates: R20.4, R20.5, R27.13
 *
 * Domain background: games marked blind (R27.13) and results of the ghost
 * team (R20.4, R20.5) must count neither towards the player average and the
 * handicap calculation base (AverageModule) nor towards the honours (honours
 * module).
 *
 * Metamorphic property: if any set of real results is extended by any
 * additional results marked blind or ghost team, the calculated average and
 * the calculated honours stay unchanged. Since the average is also the base
 * for the handicap, its invariance also covers the handicap calculation base
 * (R20.4).
 *
 * The existing pure calculation services
 * {@see \Bowlingliga\Domain\Average\AverageModule} and
 * {@see \Bowlingliga\Domain\Honours\HonoursModule} are tested.
 */
final class MetamorphicExclusionPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 11: for every generated set of real results, any added results
     * marked blind or ghost team leave the player average (including the
     * handicap base) and the honours unchanged (R20.4, R20.5, R27.13).
     *
     * Feature: bowlingliga-website, Property 11: Metamorphic exclusion of blind and ghost team results
     *
     * Validates: R20.4, R20.5, R27.13
     *
     * At least 100 inputs are generated (limitTo(100)). The matchday counter is
     * limited to >= 6, to keep the previous season special rules (R11.3/11.4)
     * out and to form the average from the current season alone.
     */
    public function testAddingBlindAndGhostTeamResultsChangesNothing(): void
    {
        $averageModule = new AverageModule();
        $honoursModule       = new HonoursModule();

        // A real, actually played game: net result (0..300),
        // evening id (1..8) and gender.
        $realGameGen = Generators::associative([
            'net'        => Generators::choose(0, 300),
            'spielabendId' => Generators::choose(1, 8),
            'female'     => Generators::elements(true, false),
            'handicap'     => Generators::choose(0, 64),
        ]);

        // An additional game to be excluded: marked blind and/or
        // ghost team (at least one of the flags set).
        $extraGameGen = Generators::associative([
            'net'        => Generators::choose(0, 300),
            'spielabendId' => Generators::choose(1, 8),
            'female'     => Generators::elements(true, false),
            'handicap'     => Generators::choose(0, 64),
            // 0 = blind only, 1 = ghost team only, 2 = blind and ghost team
            'kind'          => Generators::choose(0, 2),
        ]);

        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq($realGameGen),
                Generators::seq($extraGameGen),
                Generators::choose(6, 40)
            )
            ->then(function (
                array $realGames,
                array $additionalGames,
                int $matchdayCounter
            ) use ($averageModule, $honoursModule): void {
                // --- Build the base (real results only) ---
                $baseGames = [];
                $baseHonours     = [];
                foreach ($realGames as $index => $raw) {
                    $gender = $raw['female'] ? Gender::FEMALE : Gender::MALE;

                    $baseGames[] = new Game(
                        netResult: $raw['net'],
                        blind: false,
                        ghostTeam: false,
                        previousSeason: false,
                    );

                    $baseHonours[] = new GameResultInput(
                        playerId: 1,
                        gender: $gender,
                        eveningId: $raw['spielabendId'],
                        netResult: $raw['net'],
                        handicap: $raw['handicap'],
                        blind: false,
                        ghostTeam: false,
                    );
                }

                // --- Build the additional results to be excluded ---
                $extraGames = [];
                $extraHonours     = [];
                foreach ($additionalGames as $raw) {
                    $blind       = $raw['kind'] === 0 || $raw['kind'] === 2;
                    $ghostTeam = $raw['kind'] === 1 || $raw['kind'] === 2;
                    $gender  = $raw['female'] ? Gender::FEMALE : Gender::MALE;

                    $extraGames[] = new Game(
                        netResult: $raw['net'],
                        blind: $blind,
                        ghostTeam: $ghostTeam,
                        previousSeason: false,
                    );

                    $extraHonours[] = new GameResultInput(
                        playerId: 1,
                        gender: $gender,
                        eveningId: $raw['spielabendId'],
                        netResult: $raw['net'],
                        handicap: $raw['handicap'],
                        blind: $blind,
                        ghostTeam: $ghostTeam,
                    );
                }

                // === Average (base for the handicap, R20.4) ===
                $averageBase = $averageModule->calculateAverage(
                    1,
                    1,
                    $matchdayCounter,
                    new AverageInput($baseGames)
                );

                $averageWithExtra = $averageModule->calculateAverage(
                    1,
                    1,
                    $matchdayCounter,
                    new AverageInput(array_merge($baseGames, $extraGames))
                );

                self::assertSame(
                    $averageBase,
                    $averageWithExtra,
                    'Blind and ghost team results must not change the player average and thus the handicap basis (R20.4, R27.13).'
                );

                // === Honours (R20.5, R27.13) ===
                $honoursBase = $honoursModule->calculateHonours($baseHonours, true);
                $honoursWithExtra = $honoursModule->calculateHonours(
                    array_merge($baseHonours, $extraHonours),
                    true
                );

                self::assertEquals(
                    $honoursBase,
                    $honoursWithExtra,
                    'Blind and ghost team results must not change the honours (R20.5, R27.13).'
                );
            });
    }
}
