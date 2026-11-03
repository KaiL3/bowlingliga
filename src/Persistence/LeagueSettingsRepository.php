<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\League\LeagueSettingsStore;

/**
 * Raw data repository for the settings of the installation (table
 * `league_setting`, R38).
 *
 * The table has exactly one row (id = 1, ensured by a DB CHECK); migration
 * 016 creates it with the default name. Saving creates the row if it is
 * missing (R4.3: transactional, prepared statements).
 */
final class LeagueSettingsRepository extends AbstractRepository implements LeagueSettingsStore
{
    public function leagueName(): ?string
    {
        $row = $this->findOne('SELECT name FROM league_setting WHERE id = 1');

        return $row !== null ? (string) $row['name'] : null;
    }

    public function saveLeagueName(string $name): void
    {
        $this->transactional('Liganamen speichern', function () use ($name): void {
            $this->perform(
                'INSERT INTO league_setting (id, name) VALUES (1, :name) ON DUPLICATE KEY UPDATE name = :changed',
                ['name' => $name, 'changed' => $name]
            );
        });
    }
}
