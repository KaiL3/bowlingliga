<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\FilledLineup;
use Bowlingliga\Domain\BlindPlayerService;
use Bowlingliga\Domain\RosterPlayer;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: entering a team's lineup per evening (R21.2–R21.6).
 *
 * A team's roster per season comprises one or more players and is not limited
 * to 4 (R21.1); it is maintained via {@see PlayerManagement}. For a single
 * evening 1 to 4 real players are lined up from this roster (R21.2); the
 * {@see BlindPlayerService} fills missing positions with blind players up to
 * exactly 4 positions (R21.3, R27). This gives the domain rules:
 * - More than 4 real players are rejected (R21.4).
 * - A player who does not belong to the team's roster in this season is
 *   rejected (R21.5).
 * - If the team fields no real player at all, the evening counts as lost, the
 *   team gets 0 points and no blind player is used (R21.6); this is handled
 *   by the {@see BlindPlayerService}.
 *
 * Every writing call is protected by {@see WriteAccess} (R5.1); without a
 * valid session the action is skipped and the data stays unchanged. The
 * filled lineup is persisted transactionally as raw data ({@see LineupStore},
 * R4.1, R4.3).
 *
 * The use case holds no state of its own and is reproducible for equal inputs
 * (roster, real players); the blind player is determined deterministically by
 * the pure {@see BlindPlayerService}.
 */
final class LineupManagement
{
    /** Maximum number of real player positions of a lineup (R21.2, R21.4). */
    private const MAX_REAL_PLAYERS = 4;

    public function __construct(
        private readonly RosterReader $roster,
        private readonly LineupStore $lineups,
        private readonly BlindPlayerService $blindPlayer,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * Enters a team's lineup for a fixture: checks the real players against
     * the roster, fills missing positions with blind players up to exactly 4
     * positions and saves the lineup transactionally; returns the filled
     * lineup (R21.2–R21.6).
     *
     * The order of the real player ids passed determines positions 1..n; the
     * remaining positions are filled with blind players.
     *
     * @param list<int> $realPlayerIds 0–4 player ids of the real lineup;
     *        0 entries trigger the special case "lost" (R21.6)
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws LineupManagementException for more than 4 real players (R21.4), a
     *         player not in the roster (R21.5) or a player listed twice.
     */
    public function enterLineup(
        int $fixtureId,
        int $teamSeasonId,
        array $realPlayerIds
    ): FilledLineup {
        $this->ensureWriteAccess();

        $realPlayerIds = array_values($realPlayerIds);

        $this->ensureAtMostFourPlayers($realPlayerIds);
        $this->ensureNoDuplicatePlayers($realPlayerIds);
        $this->ensureRosterMembership($teamSeasonId, $realPlayerIds);

        $rosterForBlind = $this->buildRosterForBlindPlayer($teamSeasonId, $realPlayerIds);

        $lineup = $this->blindPlayer->fillLineup($realPlayerIds, $rosterForBlind);

        $this->lineups->saveLineup($fixtureId, $teamSeasonId, $lineup);

        return $lineup;
    }

    /**
     * Rejects a lineup with more than 4 real players (R21.4).
     *
     * @param list<int> $realPlayerIds
     *
     * @throws LineupManagementException
     */
    private function ensureAtMostFourPlayers(array $realPlayerIds): void
    {
        if (count($realPlayerIds) > self::MAX_REAL_PLAYERS) {
            throw LineupManagementException::tooManyRealPlayers(count($realPlayerIds));
        }
    }

    /**
     * Ensures that no player is listed more than once in the same lineup, so
     * that a position corresponds uniquely to a player.
     *
     * @param list<int> $realPlayerIds
     *
     * @throws LineupManagementException
     */
    private function ensureNoDuplicatePlayers(array $realPlayerIds): void
    {
        $seen = [];
        foreach ($realPlayerIds as $playerId) {
            if (isset($seen[$playerId])) {
                throw LineupManagementException::playerDuplicate($playerId);
            }

            $seen[$playerId] = true;
        }
    }

    /**
     * Ensures that every real player lined up belongs to the team's roster in
     * this season (R21.5).
     *
     * @param list<int> $realPlayerIds
     *
     * @throws LineupManagementException
     */
    private function ensureRosterMembership(int $teamSeasonId, array $realPlayerIds): void
    {
        if ($realPlayerIds === []) {
            return;
        }

        $rosterIds = $this->roster->findPlayerIdsForTeamSeason($teamSeasonId);
        $rosterIndex = array_fill_keys($rosterIds, true);

        foreach ($realPlayerIds as $playerId) {
            if (! isset($rosterIndex[$playerId])) {
                throw LineupManagementException::playerNotInRoster($playerId);
            }
        }
    }

    /**
     * Builds the roster list for determining the blind player (R27.4) and sets
     * each player's lineup status: regularly lined-up real players are marked
     * as such, so that the {@see BlindPlayerService} excludes them as blind
     * player candidates and uses the roster player not lined up with the most
     * games played.
     *
     * @param list<int> $realPlayerIds
     *
     * @return list<RosterPlayer>
     */
    private function buildRosterForBlindPlayer(int $teamSeasonId, array $realPlayerIds): array
    {
        $linedUp = array_fill_keys($realPlayerIds, true);

        $roster = [];
        foreach ($this->roster->findRosterWithPlayedGames($teamSeasonId) as $player) {
            $roster[] = new RosterPlayer(
                $player->playerId,
                $player->gamesPlayed,
                isset($linedUp[$player->playerId])
            );
        }

        return $roster;
    }

    /**
     * Ensures that write access is currently allowed (R5.1).
     *
     * @throws AccessDeniedException if there is no valid write access.
     */
    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
