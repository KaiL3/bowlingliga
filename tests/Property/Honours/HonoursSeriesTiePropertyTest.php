<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Honours;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Honours\HonourCategory;
use Bowlingliga\Domain\Honours\HonoursModule;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\Honours\CategoryResult;
use Bowlingliga\Domain\Honours\GameResultInput;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the countable series and the tie handling of the season
 * honours.
 *
 * Feature: bowlingliga-website, Property 22: Countable series and tie handling of the honours
 *
 * Validates: R13.1, R13.2, R13.4, R13.5, R13.6
 *
 * Domain background: the honours module determines places 1 to 3 per category
 * ({@see HonourCategory}) and gender ({@see Gender}) (R13.1). The following
 * applies:
 *
 * - Games marked blind (R27) and ghost team results (R20) never count towards
 *   a placement; only games played regularly by the players themselves are
 *   scored (R13.4).
 * - A countable series only exists if a player has exactly 3 regular,
 *   personally played games on a single evening; the series value is the sum
 *   of these 3 games. Evenings with a different number of regular games form
 *   no countable series (R13.2).
 * - Within a category and a gender the places are ordered descending by
 *   value; equal values share a place and the following places are skipped by
 *   the number of tied results (R13.5). If there are fewer than 3 countable
 *   results, the remaining places stay vacant (R13.6).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\Honours\HonoursModule::calculateHonours()} is
 * tested.
 */
final class HonoursSeriesTiePropertyTest extends TestCase
{
    use TestTrait;

    /** All four honour categories (R13.1). */
    private const CATEGORIES = [
        HonourCategory::HIGHEST_SERIES_NET,
        HonourCategory::HIGHEST_SERIES_GROSS,
        HonourCategory::HIGHEST_GAME_NET,
        HonourCategory::HIGHEST_GAME_GROSS,
    ];

    /** Beide Geschlechter (R13.1). */
    private const GENDERS = [
        Gender::MALE,
        Gender::FEMALE,
    ];

    /**
     * Property 22 (countable series and tie handling): for every random mix of
     * actually played, blind and ghost team games of both genders over several
     * evenings:
     *
     * - Blind and ghost team results appear in no placement and do not change
     *   the result (metamorphic property, R13.4).
     * - A countable series requires exactly 3 regular, personally played games
     *   per (player, evening); evenings with != 3 regular games form no series
     *   (R13.2).
     * - Per category and gender the places are ordered descending by value,
     *   equal values share a place, following places are skipped (R13.5),
     *   missing places stay vacant (R13.6), and the scoring is separated by
     *   gender (R13.1).
     *
     * Feature: bowlingliga-website, Property 22: Countable series and tie handling of the honours
     *
     * Validates: R13.1, R13.2, R13.4, R13.5, R13.6
     *
     * At least 100 inputs are generated (limitTo(100)).
     */
    public function testCountableSeriesAndTieHandlingOfHonours(): void
    {
        $module = new HonoursModule();

        // Generator for a single raw game: random player,
        // gender, evening, net result and handicap and a
        // marking as real / blind / ghost team. The evening space is
        // kept small, so that exactly 3 regular games per (player,
        // evening) come together often and series forming actually applies.
        $gameGen = Generators::associative([
            'spielerId'    => Generators::choose(1, 6),
            // 0 = male, 1 = female
            'gender'   => Generators::choose(0, 1),
            'spielabendId' => Generators::choose(1, 5),
            'net'        => Generators::choose(0, 300),
            'handicap'     => Generators::choose(0, 64),
            // 0 = real, 1 = blind, 2 = ghost team
            'kind'          => Generators::choose(0, 2),
        ]);

        $this
            ->limitTo(100)
            ->forAll(Generators::seq($gameGen))
            ->then(function (array $rawGames) use ($module): void {
                $results = [];
                foreach ($rawGames as $raw) {
                    $results[] = new GameResultInput(
                        playerId: $raw['spielerId'],
                        gender: $raw['gender'] === 0 ? Gender::MALE : Gender::FEMALE,
                        eveningId: $raw['spielabendId'],
                        netResult: $raw['net'],
                        handicap: $raw['handicap'],
                        blind: $raw['kind'] === 1,
                        ghostTeam: $raw['kind'] === 2,
                    );
                }

                $honours = $module->calculateHonours($results, true);

                // Only games played regularly by the players themselves count (R13.4).
                $regularOnes = array_values(array_filter(
                    $results,
                    static fn (GameResultInput $e): bool => $e->isRegularSelfPlayed()
                ));

                foreach (self::CATEGORIES as $category) {
                    foreach (self::GENDERS as $gender) {
                        $result = $honours->result($category, $gender);
                        self::assertNotNull(
                            $result,
                            'There must be a result for every combination of category and gender (R13.1).'
                        );

                        // Independent oracle: expected value list sorted descending
                        // from the regular games of the gender.
                        $expectedValues = $this->expectedValues($regularOnes, $category, $gender);

                        $this->assertPlacesCorrect($result, $expectedValues, $category, $gender);
                    }
                }

                // Metamorphic property (R13.4): adding any blind
                // and ghost team games leaves the honours unchanged.
                $withExtra = $results;
                $withExtra[] = new GameResultInput(1, Gender::MALE, 1, 300, 64, blind: true);
                $withExtra[] = new GameResultInput(1, Gender::MALE, 1, 300, 64, blind: true);
                $withExtra[] = new GameResultInput(1, Gender::MALE, 1, 300, 64, blind: true);
                $withExtra[] = new GameResultInput(2, Gender::FEMALE, 2, 300, 0, ghostTeam: true);
                $withExtra[] = new GameResultInput(2, Gender::FEMALE, 2, 300, 0, ghostTeam: true);
                $withExtra[] = new GameResultInput(2, Gender::FEMALE, 2, 300, 0, ghostTeam: true);

                $honoursWithExtra = $module->calculateHonours($withExtra, true);

                foreach (self::CATEGORIES as $category) {
                    foreach (self::GENDERS as $gender) {
                        self::assertSame(
                            $this->placesAsValueLists($honours->result($category, $gender)),
                            $this->placesAsValueLists($honoursWithExtra->result($category, $gender)),
                            'Blind and ghost team games must not change the honours (R13.4).'
                        );
                    }
                }
            });
    }

    /**
     * Independent oracle: determines the expected list of countable values of a
     * category and a gender, sorted descending, solely from the regular games
     * (R13.1, R13.2, R13.3, R13.4).
     *
     * @param list<GameResultInput> $regularOnes
     *
     * @return list<int> countable values sorted descending
     */
    private function expectedValues(array $regularOnes, HonourCategory $category, Gender $gender): array
    {
        $ofGender = array_values(array_filter(
            $regularOnes,
            static fn (GameResultInput $e): bool => $e->gender === $gender
        ));

        if ($category->isSeries()) {
            $values = $this->seriesValues($ofGender, $category);
        } else {
            $values = [];
            foreach ($ofGender as $game) {
                $values[] = $category->isGross() ? $game->grossResult() : $game->netResult;
            }
        }

        rsort($values);

        return $values;
    }

    /**
     * Forms the countable series values: per (player, evening) a series exactly
     * when there are exactly 3 regular, personally played games; the value is
     * the sum of the 3 net or gross values (R13.2, R13.3).
     *
     * @param list<GameResultInput> $ofGender
     *
     * @return list<int>
     */
    private function seriesValues(array $ofGender, HonourCategory $category): array
    {
        /** @var array<string, list<GameResultInput>> $groups */
        $groups = [];
        foreach ($ofGender as $game) {
            $groups[$game->playerId . '|' . $game->eveningId][] = $game;
        }

        $values = [];
        foreach ($groups as $group) {
            // R13.2: only exactly 3 regular games form a countable series.
            if (count($group) !== 3) {
                continue;
            }

            $sum = 0;
            foreach ($group as $game) {
                $sum += $category->isGross() ? $game->grossResult() : $game->netResult;
            }

            $values[] = $sum;
        }

        return $values;
    }

    /**
     * Checks that the places awarded by the module match the expected values
     * sorted descending exactly: rank assignment with shared ties and skipped
     * following places (R13.5), filling missing places as vacant (R13.6) and
     * descending order (R13.1).
     *
     * @param list<int> $expectedValues sorted descending
     */
    private function assertPlacesCorrect(
        CategoryResult $result,
        array $expectedValues,
        HonourCategory $category,
        Gender $gender
    ): void {
        $context = sprintf('Kategorie %s, Geschlecht %s', $category->value, $gender->value);

        // Derive the expected ranks independently: equal values share a
        // rank; the next rank jumps by the number of tied values
        // (R13.5). Only ranks 1 to 3 are awarded (R13.1).
        $expectedPlaces = [];
        $nextRank = 1;
        $index = 0;
        $quantity = count($expectedValues);
        while ($index < $quantity && $nextRank <= 3) {
            $value = $expectedValues[$index];
            $equal = [];
            while ($index < $quantity && $expectedValues[$index] === $value) {
                $equal[] = $value;
                $index++;
            }
            $expectedPlaces[$nextRank] = $equal;
            $nextRank += count($equal);
        }

        $highestOccupiedRank = 0;
        foreach ($expectedPlaces as $rank => $equal) {
            $highestOccupiedRank = max($highestOccupiedRank, $rank + count($equal) - 1);
        }

        $priorValue = null;
        foreach ($result->places as $place) {
            // Rank always in the valid range (R13.1).
            self::assertGreaterThanOrEqual(1, $place->rank, $context . ': Rang >= 1 (R13.1).');
            self::assertLessThanOrEqual(3, $place->rank, $context . ': Rang <= 3 (R13.1).');

            if (isset($expectedPlaces[$place->rank])) {
                $expectedGroup = $expectedPlaces[$place->rank];

                // The number of tied results must match (R13.5).
                self::assertCount(
                    count($expectedGroup),
                    $place->placements,
                    $context . sprintf(': Anzahl gleichrangiger Ergebnisse auf Rang %d (R13.5).', $place->rank)
                );

                // All values of the place must equal the expected value.
                $expectedValue = $expectedGroup[0];
                foreach ($place->placements as $placement) {
                    self::assertSame(
                        $expectedValue,
                        $placement->value,
                        $context . sprintf(': Wert auf Rang %d (R13.5).', $place->rank)
                    );
                }

                self::assertTrue($place->isShared() === (count($expectedGroup) > 1), $context . ': istGeteilt (R13.5).');
                self::assertFalse($place->isVacant(), $context . ': belegter Platz ist nicht unbesetzt.');

                // Descending value order across the taken places (R13.1).
                if ($priorValue !== null) {
                    self::assertLessThan(
                        $priorValue,
                        $expectedValue,
                        $context . ': places are sorted descending by value (R13.1).'
                    );
                }
                $priorValue = $expectedValue;
            } else {
                // A rank not taken must be vacant (R13.6).
                self::assertTrue(
                    $place->isVacant(),
                    $context . sprintf(': Rang %d muss unbesetzt sein (R13.6).', $place->rank)
                );
            }
        }

        // Every rank 1..3 that is neither taken nor skipped by a tie
        // must appear as a vacant place (R13.6).
        $existingRanks = [];
        foreach ($result->places as $place) {
            $existingRanks[$place->rank] = $place;
        }

        for ($rank = 1; $rank <= 3; $rank++) {
            $isSkipped = $rank <= $highestOccupiedRank && !isset($expectedPlaces[$rank]);
            if ($isSkipped) {
                // Following rank covered by a shared place: does not appear
                // as a place of its own (R13.5).
                self::assertArrayNotHasKey(
                    $rank,
                    $existingRanks,
                    $context . sprintf(': skipped rank %d does not appear as a place of its own (R13.5).', $rank)
                );

                continue;
            }

            self::assertArrayHasKey(
                $rank,
                $existingRanks,
                $context . sprintf(': rank %d must exist (R13.6).', $rank)
            );

            if (!isset($expectedPlaces[$rank])) {
                self::assertTrue(
                    $existingRanks[$rank]->isVacant(),
                    $context . sprintf(': Rang %d muss unbesetzt sein (R13.6).', $rank)
                );
            }
        }
    }

    /**
     * Reduces a CategoryResult to the list of value lists per place
     * (rank → values), to be able to compare two results structurally.
     *
     * @return array<int, list<int>>
     */
    private function placesAsValueLists(?CategoryResult $result): array
    {
        self::assertNotNull($result, 'A category result must not be null (R13.1).');

        $valueLists = [];
        foreach ($result->places as $place) {
            $values = [];
            foreach ($place->placements as $placement) {
                $values[] = $placement->value;
            }
            sort($values);
            $valueLists[$place->rank] = $values;
        }

        ksort($valueLists);

        return $valueLists;
    }
}
