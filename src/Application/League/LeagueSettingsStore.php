<?php

declare(strict_types=1);

namespace Bowlingliga\Application\League;

/**
 * Port for the settings of the whole installation (R38, R40).
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
     * Whether player data is public without login (R40.1); false without a
     * stored row.
     */
    public function playersPublic(): bool;

    /**
     * Saves the (validated) league name and the player visibility in one
     * step; creates or updates the row.
     */
    public function save(string $name, bool $playersPublic): void;
}
