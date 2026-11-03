<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

use Bowlingliga\Application\RosterReader;

/**
 * Order in which the roster players of a team serve as blind players on an
 * evening (R27.4, R27.14, R27.15).
 *
 * A missing player is replaced by a roster player who does not play that
 * evening: first the one with the most games played so far (before the
 * matchday, {@see PlayedGamesReader}), then the next one. Each player serves
 * as blind player at most once per matchday – they get exactly one row on the
 * score sheet, in which all their games are blind. Ties are listed by the
 * smaller player id, but any of the tied players is accepted.
 *
 * Reading service without side effects; used by {@see ResultRecording} to
 * check the blind players when saving and by the result entry page for the
 * hint on the order.
 */
final class BlindPlayerOrder
{
    public function __construct(
        private readonly RosterReader $roster,
        private readonly PlayedGamesReader $playedGames,
    ) {
    }

    /**
     * Roster players who do not play that evening, by games played so far
     * (descending), then player id.
     *
     * @param list<int> $participantIds players who play at least one game
     *        themselves that evening
     *
     * @return list<array{player_id: int, games: int}>
     */
    public function candidates(int $teamSeasonId, int $matchdayId, array $participantIds): array
    {
        $participating = array_fill_keys($participantIds, true);

        $candidates = [];
        foreach ($this->roster->findPlayerIdsForTeamSeason($teamSeasonId) as $playerId) {
            if (! isset($participating[$playerId])) {
                $candidates[] = ['player_id' => $playerId, 'games' => $this->playedGames->gamesPlayedBefore($matchdayId, $playerId)];
            }
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => [$b['games'], $a['player_id']] <=> [$a['games'], $b['player_id']],
        );

        return $candidates;
    }

    /**
     * Checks that the blind players are the candidates with the most games:
     * no candidate who is not used may have more games than a blind player
     * (R27.4, R27.15).
     *
     * @param list<int>          $participantIds players who play themselves
     * @param list<int>          $blindPlayerIds players serving as blind player
     * @param array<int, string> $playerNames    player id → name for messages
     *
     * @throws ResultEntryException
     */
    public function check(
        int $teamSeasonId,
        int $matchdayId,
        array $participantIds,
        array $blindPlayerIds,
        string $team,
        array $playerNames = [],
    ): void {
        if ($blindPlayerIds === []) {
            return;
        }

        $games = [];
        foreach ($this->candidates($teamSeasonId, $matchdayId, $participantIds) as $candidate) {
            $games[$candidate['player_id']] = $candidate['games'];
        }
        $chosen = array_fill_keys($blindPlayerIds, true);
        $name   = static fn (int $id): string => $playerNames[$id] ?? 'Spieler ' . $id;

        foreach ($blindPlayerIds as $blindId) {
            foreach ($games as $candidateId => $count) {
                if (! isset($chosen[$candidateId]) && $count > ($games[$blindId] ?? 0)) {
                    throw ResultEntryException::wrongBlindPlayer(
                        $team,
                        $name($candidateId),
                        $count,
                        $name($blindId),
                        $games[$blindId] ?? 0,
                    );
                }
            }
        }
    }
}
