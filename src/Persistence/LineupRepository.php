<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\LineupStore;
use Bowlingliga\Domain\FilledLineup;

/**
 * Raw data repository for lineups (table `lineup`, R21, R27).
 *
 * A lineup row is one row of a team's score sheet in a fixture (`sheet_row`
 * 1..n): a roster player with the games they played (`is_blind = 1` if they
 * serve as blind player per R27.4) or a row of the ghost team (`player_id`
 * NULL). Since players can be substituted between games, a team can have
 * more than 4 rows (R21.2, R27.17). The rows are written together with the
 * results by the
 * {@see \Bowlingliga\Persistence\ResultRepository}. Write operations
 * transactional (R4.3), prepared statements (R4.1), reads unchanged (R4.5).
 *
 * Also implements the {@see LineupStore} port of the deactivated
 * {@see \Bowlingliga\Application\LineupManagement} (lineup of 4 positions
 * entered before the results).
 */
final class LineupRepository extends AbstractRepository implements LineupStore
{
    /**
     * Creates a lineup row and returns its id (R21.2).
     */
    public function create(
        int $fixtureId,
        int $teamSeasonId,
        int $sheetRow,
        ?int $playerId
    ): int {
        return $this->transactional('create_lineup', function ($pdo) use ($fixtureId, $teamSeasonId, $sheetRow, $playerId): int {
            $this->perform(
                'INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id)
                 VALUES (:fixture_id, :team_season_id, :sheet_row, :player_id)',
                [
                    'fixture_id'     => $fixtureId,
                    'team_season_id' => $teamSeasonId,
                    'sheet_row'      => $sheetRow,
                    'player_id'      => $playerId,
                ]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Sets the player of a lineup row (NULL = row without player).
     */
    public function setPlayer(int $id, ?int $playerId): void
    {
        $this->transactional('set_lineup_player', function () use ($id, $playerId): void {
            $this->perform(
                'UPDATE lineup SET player_id = :player_id WHERE id = :id',
                ['id' => $id, 'player_id' => $playerId]
            );
        });
    }

    /**
     * Deletes a lineup row (R4).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_lineup', function () use ($id): void {
            $this->perform('DELETE FROM lineup WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a lineup row or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, fixture_id, team_season_id, sheet_row, player_id FROM lineup WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all lineup rows of a team within a fixture (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForFixtureAndTeam(int $fixtureId, int $teamSeasonId): array
    {
        return $this->findAll(
            'SELECT id, fixture_id, team_season_id, sheet_row, player_id
             FROM lineup
             WHERE fixture_id = :fixture_id AND team_season_id = :team_season_id
             ORDER BY sheet_row',
            ['fixture_id' => $fixtureId, 'team_season_id' => $teamSeasonId]
        );
    }

    /**
     * Saves a team's lineup within a fixture, filled up to exactly 4
     * positions, transactionally as raw data (R21.2–3, R21.6) – used by the
     * deactivated {@see \Bowlingliga\Application\LineupManagement}.
     *
     * An existing lineup of the same team in this fixture is replaced
     * completely. Position n becomes sheet row n; in the special case "no
     * real player" ({@see FilledLineup::$lost}) no row is created (R21.6).
     */
    public function saveLineup(
        int $fixtureId,
        int $teamSeasonId,
        FilledLineup $lineup
    ): void {
        $this->transactional('save_lineup', function () use ($fixtureId, $teamSeasonId, $lineup): void {
            $this->perform(
                'DELETE FROM lineup
                 WHERE fixture_id = :fixture_id AND team_season_id = :team_season_id',
                ['fixture_id' => $fixtureId, 'team_season_id' => $teamSeasonId]
            );

            foreach ($lineup->positions as $position) {
                $this->perform(
                    'INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id, is_blind)
                     VALUES (:fixture_id, :team_season_id, :sheet_row, :player_id, :is_blind)',
                    [
                        'fixture_id'     => $fixtureId,
                        'team_season_id' => $teamSeasonId,
                        'sheet_row'      => $position->position,
                        // Without a roster player to substitute, the
                        // blind player service returns 0; NULL is stored then.
                        'player_id'      => $position->playerId > 0 ? $position->playerId : null,
                        'is_blind'       => $position->isBlind ? 1 : 0,
                    ]
                );
            }
        });
    }
}
