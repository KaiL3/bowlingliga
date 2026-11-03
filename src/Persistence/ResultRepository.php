<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\ResultEntry\EnteredGame;
use Bowlingliga\Application\ResultEntry\EnteredResults;
use Bowlingliga\Application\ResultEntry\ResultStore;

/**
 * Persistence adapter for transactionally saving and removing the entered
 * game results of a fixture (R22.6, R22.7, R24.4, R35.1).
 *
 * Implements the {@see ResultStore} port. The lineup of an evening follows
 * from the entered results: every row of a team's score sheet becomes one
 * `lineup` row – roster players in input order (`is_blind = 1` for a player
 * serving as blind player, i.e. with only blind games), the ghost team rows
 * without player –, and each entered game one `game_result` row with the net
 * sheet value (R27.10) and the blind flag.
 *
 * Existing lineup rows of the fixture (and with them their results) are
 * removed before writing, so that draft, confirmation and correction use the
 * same path and no outdated row remains. The whole operation runs in one
 * transaction (R4.3).
 */
final class ResultRepository extends AbstractRepository implements ResultStore
{
    public function saveResults(EnteredResults $results): void
    {
        $this->transactional('Einzelspiel-Ergebnisse speichern', function () use ($results): void {
            $this->removeInternal($results->fixtureId);

            foreach ($results->gamesByTeam() as $teamSeasonId => $games) {
                $sheetRow = 0;
                foreach ($this->rows($games) as $rowGames) {
                    $onlyBlind = array_filter($rowGames, static fn (EnteredGame $g): bool => ! $g->isBlind) === [];
                    $lineupId = $this->insertLineupRow(
                        $results->fixtureId,
                        $teamSeasonId,
                        ++$sheetRow,
                        $rowGames[0]->playerId,
                        $onlyBlind,
                    );

                    foreach ($rowGames as $game) {
                        $this->perform(
                            'INSERT INTO game_result (lineup_id, game_number, net, is_blind)
                             VALUES (:lineup_id, :game_number, :net, :is_blind)',
                            [
                                'lineup_id'   => $lineupId,
                                'game_number' => $game->gameNumber,
                                'net'         => $game->net,
                                'is_blind'    => $game->isBlind ? 1 : 0,
                            ]
                        );
                    }
                }
            }
        });
    }

    public function findBlindPlayers(int $fixtureId, int $teamSeasonId): array
    {
        $rows = $this->findAll(
            'SELECT DISTINCT player_id FROM lineup
             WHERE fixture_id = :fixture_id AND team_season_id = :team_season_id
               AND is_blind = 1 AND player_id IS NOT NULL
             ORDER BY player_id',
            ['fixture_id' => $fixtureId, 'team_season_id' => $teamSeasonId]
        );

        return array_map(static fn (array $row): int => (int) $row['player_id'], $rows);
    }

    public function removeResults(int $fixtureId): void
    {
        $this->transactional('Einzelspiel-Ergebnisse entfernen', function () use ($fixtureId): void {
            $this->removeInternal($fixtureId);
        });
    }

    public function hasResults(int $fixtureId): bool
    {
        $row = $this->findOne(
            'SELECT COUNT(e.id) AS quantity
             FROM game_result e
             JOIN lineup a ON a.id = e.lineup_id
             WHERE a.fixture_id = :fixture_id',
            ['fixture_id' => $fixtureId]
        );

        return $row !== null && (int) $row['quantity'] > 0;
    }

    public function findGames(int $fixtureId): array
    {
        $rows = $this->findAll(
            'SELECT a.team_season_id, a.player_id, e.game_number, e.net, e.is_blind
             FROM game_result e
             JOIN lineup a ON a.id = e.lineup_id
             WHERE a.fixture_id = :fixture_id AND a.player_id IS NOT NULL
             ORDER BY a.team_season_id, a.sheet_row, e.game_number',
            ['fixture_id' => $fixtureId]
        );

        return array_map(static fn (array $row): EnteredGame => EnteredGame::player(
            (int) $row['team_season_id'],
            (int) $row['player_id'],
            (int) $row['game_number'],
            (int) $row['net'],
            (bool) $row['is_blind'],
        ), $rows);
    }

    /**
     * Groups the games of a team into the rows of the score sheet: player rows
     * in input order, then the ghost team rows or blind slots by number.
     *
     * @param list<EnteredGame> $games
     *
     * @return list<non-empty-list<EnteredGame>>
     */
    private function rows(array $games): array
    {
        $players = [];
        $slots   = [];
        foreach ($games as $game) {
            if ($game->isPlayerRow()) {
                $players[(int) $game->playerId][] = $game;
            } else {
                $slots[$game->slot][] = $game;
            }
        }
        ksort($slots);

        return [...array_values($players), ...array_values($slots)];
    }

    private function insertLineupRow(int $fixtureId, int $teamSeasonId, int $sheetRow, ?int $playerId, bool $isBlind): int
    {
        $this->perform(
            'INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id, is_blind)
             VALUES (:fixture_id, :team_season_id, :sheet_row, :player_id, :is_blind)',
            [
                'fixture_id'     => $fixtureId,
                'team_season_id' => $teamSeasonId,
                'sheet_row'      => $sheetRow,
                'player_id'      => $playerId,
                'is_blind'       => $isBlind ? 1 : 0,
            ]
        );

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * Removes all lineup rows of a fixture; their game results follow via
     * ON DELETE CASCADE. Expects an already opened transaction context.
     */
    private function removeInternal(int $fixtureId): void
    {
        $this->perform('DELETE FROM lineup WHERE fixture_id = :fixture_id', ['fixture_id' => $fixtureId]);
    }
}
