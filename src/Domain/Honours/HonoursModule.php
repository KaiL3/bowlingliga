<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Honours;

/**
 * Pure calculation service for the season honours (R13, R20.5, R27.13).
 *
 * The service is free of side effects: it neither accesses the database nor
 * persists anything, but receives the raw games already collected as a list
 * of {@see GameResultInput} and produces the complete {@see Honours} from
 * them. This makes the honours calculable reproducibly at any time – even
 * after every game (R13.7, R35.2) – and fully verifiable by property tests.
 *
 * For each of the four categories ({@see HonourCategory}) places 1 to 3 are
 * determined separately by gender (R13.1). The following applies:
 *
 * - Only games played regularly by the player personally are considered;
 *   games marked blind (R27.13) and ghost team results (R20.5) are excluded
 *   (R13.4).
 * - A countable series only exists if a player has exactly 3 regular,
 *   personally played games on a single evening; the series value is the sum
 *   of these 3 games (R13.2). An evening with a different number of regular
 *   games forms no countable series (R13.2).
 * - The gross value is the sum of the net value and the handicap (R13.3).
 * - On a tie all affected results get the same place; the following places
 *   are skipped by the number of tied results (R13.5).
 * - If there are fewer than 3 countable results, only the existing places are
 *   taken and the others are marked vacant (R13.6).
 *
 * Visibility is represented deterministically via the {$isAfterEndDate} flag
 * and carried into the {@see Honours} result; whether the season's end date
 * has passed is decided by the calling layer via a date comparison so that
 * the calculation stays free of time side effects (R13.9, R13.10, R35).
 */
final class HonoursModule
{
    private const CATEGORIES = [
        HonourCategory::HIGHEST_SERIES_NET,
        HonourCategory::HIGHEST_SERIES_GROSS,
        HonourCategory::HIGHEST_GAME_NET,
        HonourCategory::HIGHEST_GAME_GROSS,
    ];

    private const GENDERS = [
        Gender::MALE,
        Gender::FEMALE,
    ];

    /**
     * Number of regular, personally played games of one evening that form a
     * countable series (R13.2).
     */
    private const GAMES_PER_SERIES = 3;

    /** Highest place to award per category and gender (R13.1). */
    private const PLACE_COUNT = 3;

    /**
     * Calculates the honours of a season from the raw games already collected
     * (R13).
     *
     * For each combination of category and gender places 1 to 3 are determined
     * (R13.1). Blind and ghost team results are excluded (R13.4, R20.5,
     * R27.13). Visibility follows from the flag passed in (R13.9, R13.10).
     *
     * @param list<GameResultInput> $results        All raw games collected for
     *                                              the season (real/blind/ghost
     *                                              team mixed).
     * @param bool                  $isAfterEndDate True if the season's end date
     *                                              has passed (R13.10).
     *                                              Determines the visibility in
     *                                              the result.
     */
    public function calculateHonours(array $results, bool $isAfterEndDate): Honours
    {
        // Only games played regularly by the players themselves are considered;
        // blind and ghost team results are excluded (R13.4,
        // R20.5, R27.13).
        $regularOnes = array_values(array_filter(
            $results,
            static fn (GameResultInput $e): bool => $e->isRegularSelfPlayed()
        ));

        $categoryResults = [];
        foreach (self::CATEGORIES as $category) {
            foreach (self::GENDERS as $gender) {
                $candidates = $this->determineCandidates($regularOnes, $category, $gender);
                $places = $this->assignPlaces($candidates);
                $categoryResults[] = new CategoryResult($category, $gender, $places);
            }
        }

        return new Honours($categoryResults, $isAfterEndDate);
    }

    /**
     * Determines the countable placements for one category and one gender from
     * the regular games of that gender.
     *
     * For game categories every single regular game is a placement; for series
     * categories a series of exactly 3 regular games is formed per player and
     * evening (R13.2). Depending on the category the value is a net or a gross
     * value (R13.3).
     *
     * @param list<GameResultInput> $regularOnes
     *
     * @return list<HonourPlacement>
     */
    private function determineCandidates(array $regularOnes, HonourCategory $category, Gender $gender): array
    {
        $ofGender = array_values(array_filter(
            $regularOnes,
            static fn (GameResultInput $e): bool => $e->gender === $gender
        ));

        return $category->isSeries()
            ? $this->seriesCandidates($ofGender, $category)
            : $this->gameCandidates($ofGender, $category);
    }

    /**
     * Forms the placements of the game categories: every regular game is a
     * placement with its net or gross value (R13.3).
     *
     * @param list<GameResultInput> $results
     *
     * @return list<HonourPlacement>
     */
    private function gameCandidates(array $results, HonourCategory $category): array
    {
        $candidates = [];
        foreach ($results as $result) {
            $value = $category->isGross() ? $result->grossResult() : $result->netResult;
            $candidates[] = new HonourPlacement($result->playerId, $value, $result->playerName);
        }

        return $candidates;
    }

    /**
     * Forms the placements of the series categories: per player and evening a
     * series is only scored if there are exactly 3 regular, personally played
     * games; the value is the sum of the 3 net or gross values (R13.2, R13.3).
     *
     * @param list<GameResultInput> $results
     *
     * @return list<HonourPlacement>
     */
    private function seriesCandidates(array $results, HonourCategory $category): array
    {
        /** @var array<string, list<GameResultInput>> $groups */
        $groups = [];
        foreach ($results as $result) {
            $key = $result->playerId . '|' . $result->eveningId;
            $groups[$key][] = $result;
        }

        $candidates = [];
        foreach ($groups as $group) {
            // A countable series requires exactly 3 regular, personally played
            // games of the same evening (R13.2). Evenings with a different
            // number form no series.
            if (count($group) !== self::GAMES_PER_SERIES) {
                continue;
            }

            $value = 0;
            foreach ($group as $game) {
                $value += $category->isGross() ? $game->grossResult() : $game->netResult;
            }

            $former = $group[0];
            $candidates[] = new HonourPlacement($former->playerId, $value, $former->playerName);
        }

        return $candidates;
    }

    /**
     * Awards places 1 to 3 from the countable placements (R13.1).
     *
     * The placements are sorted descending by value. Equal values share a
     * place; the following places are skipped by the number of tied results
     * (R13.5). Places not taken are added as vacant (R13.6). At most the three
     * highest ranks are returned.
     *
     * @param list<HonourPlacement> $candidates
     *
     * @return list<HonourPlace>
     */
    private function assignPlaces(array $candidates): array
    {
        $groups = $this->groupByValue($candidates);

        $places = [];
        $nextRank = 1;
        foreach ($groups as $group) {
            if ($nextRank > self::PLACE_COUNT) {
                break;
            }

            $places[] = new HonourPlace($nextRank, $group);
            // Skip the following places by the number of tied
            // results (R13.5).
            $nextRank += count($group);
        }

        return $this->fillVacantPlaces($places);
    }

    /**
     * Groups the placements descending by value; each group contains the tied
     * results of one value (R13.5).
     *
     * @param list<HonourPlacement> $candidates
     *
     * @return list<list<HonourPlacement>>
     */
    private function groupByValue(array $candidates): array
    {
        usort(
            $candidates,
            static fn (HonourPlacement $a, HonourPlacement $b): int => $b->value <=> $a->value
        );

        $groups = [];
        $currentValue = null;
        foreach ($candidates as $placement) {
            if ($currentValue === null || $placement->value !== $currentValue) {
                $groups[] = [];
                $currentValue = $placement->value;
            }

            $groups[count($groups) - 1][] = $placement;
        }

        return $groups;
    }

    /**
     * Adds a vacant place for every rank 1 to 3 not yet taken by the rank
     * assignment, so that a CategoryResult always shows the existing and the
     * vacant places (R13.6).
     *
     * Ranks skipped after a tie (R13.5) are not added as a separate place,
     * since they are covered by the shared place; only real gaps at the end –
     * because of fewer than 3 countable results – are filled (R13.6).
     *
     * @param list<HonourPlace> $places
     *
     * @return list<HonourPlace>
     */
    private function fillVacantPlaces(array $places): array
    {
        $occupiedRanks = [];
        $highestOccupiedRank = 0;
        foreach ($places as $place) {
            $occupiedRanks[$place->rank] = true;
            // A shared place also covers the skipped following ranks
            // (R13.5), which therefore do not count as vacant.
            $highestOccupiedRank = max(
                $highestOccupiedRank,
                $place->rank + count($place->placements) - 1
            );
        }

        for ($rank = 1; $rank <= self::PLACE_COUNT; $rank++) {
            if ($rank <= $highestOccupiedRank || isset($occupiedRanks[$rank])) {
                continue;
            }

            $places[] = new HonourPlace($rank);
        }

        usort(
            $places,
            static fn (HonourPlace $a, HonourPlace $b): int => $a->rank <=> $b->rank
        );

        return $places;
    }
}
