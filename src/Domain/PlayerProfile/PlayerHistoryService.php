<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\PlayerProfile;

use Bowlingliga\Domain\Average\AverageInput;
use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Average\PreviousSeasonRecord;

/**
 * Pure calculation of a player's history within one season (R39.3, R39.4).
 *
 * For every played matchday k the point holds the player average after
 * matchday k – the same value as the {@see AverageModule} calculates, so up
 * to the season setting "previous season in the average" it includes the
 * previous season (R11.3–R11.6) –, the mean of the games of this evening and
 * the frozen handicap.
 *
 * The history starts with the first matchday on which the player has an
 * average: the first matchday they played, or – if a previous season counts
 * – already the first matchday of the season with the previous season value
 * (R39.4). Matchdays after the start on which the player did not play keep
 * their point; the average then stays as it is unless the previous season
 * drops out.
 */
final class PlayerHistoryService
{
    public function __construct(
        private readonly AverageModule $averages = new AverageModule(),
    ) {
    }

    /**
     * @param list<PlayerMatchdayInput> $matchdays               Played matchdays of the season, any order.
     * @param list<Game>                $previousSeasonGames     The player's games of the preceding season
     *                                                           (only if the season has no record for them, R29.2).
     * @param PreviousSeasonRecord|null $record                  The season's previous season record of the player (R29).
     * @param int                       $previousSeasonMatchdays Last matchday counter including the previous season (0 = never).
     */
    public function history(
        array $matchdays,
        array $previousSeasonGames = [],
        ?PreviousSeasonRecord $record = null,
        int $previousSeasonMatchdays = AverageModule::PREVIOUS_SEASON_THRESHOLD,
    ): PlayerHistory {
        usort($matchdays, static fn (PlayerMatchdayInput $a, PlayerMatchdayInput $b): int => $a->matchdayNumber <=> $b->matchdayNumber);

        $previous = array_map(
            static fn (Game $game): Game => new Game($game->netResult, $game->blind, $game->ghostTeam, true),
            $previousSeasonGames,
        );

        $seasonGames = [];
        $points      = [];
        foreach ($matchdays as $matchday) {
            $played = array_values(array_filter(
                $matchday->games,
                static fn (Game $game): bool => $game->isActuallyPlayed(),
            ));
            foreach ($played as $game) {
                $seasonGames[] = new Game($game->netResult);
            }

            $average = $this->averages->calculateExactAverage(
                max(1, $matchday->matchdayNumber),
                new AverageInput([...$seasonGames, ...$previous], $record),
                $previousSeasonMatchdays,
            );

            // The history starts with the first average (R39.4).
            if ($points === [] && $average === null) {
                continue;
            }

            $points[] = new PlayerHistoryPoint(
                $matchday->matchdayId,
                $matchday->matchdayNumber,
                $matchday->date,
                $average,
                $played === [] ? null : array_sum(array_map(static fn (Game $g): int => $g->netResult, $played)) / count($played),
                count($played),
                $matchday->handicap,
            );
        }

        return new PlayerHistory($points);
    }
}
