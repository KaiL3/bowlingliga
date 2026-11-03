<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\FilledLineup;

/**
 * Port for persisting a team's lineup on an evening (R21.2, R21.3, R21.6).
 *
 * Decouples {@see LineupManagement} from the concrete persistence technology.
 * The use case passes the lineup already filled up to exactly 4 positions
 * ({@see FilledLineup}); the implementation persists it transactionally as
 * raw data (R4.1, R4.3).
 *
 * The port is implemented by an adapter based on the
 * {@see \Bowlingliga\Persistence\LineupRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface LineupStore
{
    /**
     * Saves a team's lineup within a fixture transactionally as raw data; an
     * existing lineup of the same team in this fixture is replaced (R21.2,
     * R21.3).
     *
     * In the special case "no real player" ({@see FilledLineup::$lost}) the
     * lineup has no positions; no position and no blind player are saved
     * (R21.6).
     */
    public function saveLineup(
        int $fixtureId,
        int $teamSeasonId,
        FilledLineup $lineup
    ): void;
}
