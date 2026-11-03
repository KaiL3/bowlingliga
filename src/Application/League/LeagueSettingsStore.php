<?php

declare(strict_types=1);

namespace Bowlingliga\Application\League;

/**
 * Port for the settings of the whole installation (R38).
 *
 * Decouples {@see LeagueSettings} from the persistence technology; the
 * adapter is based on the single-row table `league_setting`, in tests an
 * in-memory fake is enough.
 */
interface LeagueSettingsStore
{
    /**
     * Returns the stored league name, or null if none is stored yet.
     */
    public function leagueName(): ?string;

    /**
     * Saves the (validated) league name; creates or updates the row.
     */
    public function saveLeagueName(string $name): void;
}
