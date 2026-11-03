<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\Import;

use Bowlingliga\Application\Import\PlayerImportReader;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Read adapter of the player import: seasons, player names and teams.
 */
final class PlayerImportRepository extends AbstractRepository implements PlayerImportReader
{
    public function seasonExists(int $seasonId): bool
    {
        return $this->findOne('SELECT id FROM season WHERE id = :id', ['id' => $seasonId]) !== null;
    }

    public function playerNames(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->findAll('SELECT name FROM player ORDER BY name')
        );
    }

    public function seasonTeams(int $seasonId): array
    {
        return array_map(
            static fn (array $row): array => [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['team_name'],
                'is_ghost_team' => (int) $row['is_ghost_team'] === 1,
            ],
            $this->findAll(
                'SELECT id, team_name, is_ghost_team FROM team_season WHERE season_id = :season_id ORDER BY team_name',
                ['season_id' => $seasonId]
            )
        );
    }
}
