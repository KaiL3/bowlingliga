<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\League\LeagueSettingsStore;

/**
 * Raw data repository for the settings of the installation (table
 * `league_setting`, R38, R40).
 *
 * The table has exactly one row (id = 1, ensured by a DB CHECK); migration
 * 001 creates it with the default name, migration 003 adds the player
 * visibility. Saving creates the row if it is missing (R4.3: transactional,
 * prepared statements).
 */
final class LeagueSettingsRepository extends AbstractRepository implements LeagueSettingsStore
{
    public function leagueName(): ?string
    {
        $row = $this->findOne('SELECT name FROM league_setting WHERE id = 1');

        return $row !== null ? (string) $row['name'] : null;
    }

    public function playersPublic(): bool
    {
        $row = $this->findOne('SELECT players_public FROM league_setting WHERE id = 1');

        return $row !== null && (int) $row['players_public'] === 1;
    }

    public function save(string $name, bool $playersPublic): void
    {
        $this->transactional('save_league_settings', function () use ($name, $playersPublic): void {
            $this->perform(
                'INSERT INTO league_setting (id, name, players_public) VALUES (1, :name, :public)'
                . ' ON DUPLICATE KEY UPDATE name = :changed, players_public = :changed_public',
                ['name' => $name, 'public' => (int) $playersPublic, 'changed' => $name, 'changed_public' => (int) $playersPublic]
            );
        });
    }
}
