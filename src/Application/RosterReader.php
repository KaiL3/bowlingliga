<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\RosterPlayer;

/**
 * Port for read access to the roster of a team season (R21.1, R21.5, R27.4).
 *
 * Decouples {@see LineupManagement} from the concrete persistence technology:
 * for an evening the use case needs a team's roster in order to
 * - check that the lined-up players belong to the roster (R21.5) and
 * - determine the substitute blind player via the
 *   {@see \Bowlingliga\Domain\BlindPlayerService} (R27.4).
 *
 * The returned {@see RosterPlayer} carry the games played as of the matchday;
 * whether a player is lined up regularly is set by {@see LineupManagement}
 * based on the concrete lineup and is not part of the raw state read here.
 *
 * The port is implemented by an adapter based on the
 * {@see \Bowlingliga\Persistence\RosterRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface RosterReader
{
    /**
     * Returns the player ids of a team season's roster (R21.1, R21.5).
     *
     * @return list<int>
     */
    public function findPlayerIdsForTeamSeason(int $teamSeasonId): array;

    /**
     * Returns the roster players of a team season with the games played as of
     * the matchday, for determining the blind player (R27.4).
     *
     * The field {@see RosterPlayer::$regularlyLinedUp} is always `false` in
     * the result; the regular lineup only follows from the lineup actually
     * entered for the evening and is set by the use case.
     *
     * @return list<RosterPlayer>
     */
    public function findRosterWithPlayedGames(int $teamSeasonId): array;
}
