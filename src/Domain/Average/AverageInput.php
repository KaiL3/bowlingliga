<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Average;

/**
 * Immutable input value object (raw data excerpt) for calculating a player's
 * average in the AverageModule (R11).
 *
 * The object bundles exactly the raw data from which a player's average is
 * calculated reproducibly (R35) and without side effects:
 *
 * - {$games}: the player's individual net games, each including its flags
 *   (blind, ghost team, previous season). All games attributable to the
 *   player may be included; the inclusion and exclusion logic is handled
 *   solely by the AverageModule.
 * - {$previousSeasonRecord}: the optional manually entered previous season
 *   record (R11.4, R29), used only as a substitute during the first five
 *   matchdays when no actually played previous season games exist.
 *
 * The class has no database or persistence dependency; all raw data is
 * passed in from outside.
 */
final class AverageInput
{
    /** @var list<Game> */
    public readonly array $games;

    /**
     * @param list<Game>                $games                All games assigned to the player
     *                                                        (current and previous season,
     *                                                        real/blind/ghost team mixed).
     * @param PreviousSeasonRecord|null $previousSeasonRecord Optional manual previous season
     *                                                        record (R11.4, R29).
     */
    public function __construct(
        array $games = [],
        public readonly ?PreviousSeasonRecord $previousSeasonRecord = null,
    ) {
        // Re-indexing guarantees a dense list regardless of the keys passed in
        // and protects the pure calculation logic from assumptions about gaps.
        $this->games = array_values($games);
    }

    /**
     * Returns the games of the current season actually played by the player
     * (neither blind nor ghost team, not previous season, R11.1, R11.5).
     *
     * @return list<Game>
     */
    public function realGamesCurrentSeason(): array
    {
        return array_values(array_filter(
            $this->games,
            static fn (Game $game): bool => $game->isActuallyPlayed() && !$game->previousSeason
        ));
    }

    /**
     * Returns the games of the previous season actually played by the player
     * (neither blind nor ghost team, R11.1, R11.3).
     *
     * @return list<Game>
     */
    public function realGamesPreviousSeason(): array
    {
        return array_values(array_filter(
            $this->games,
            static fn (Game $game): bool => $game->isActuallyPlayed() && $game->previousSeason
        ));
    }
}
