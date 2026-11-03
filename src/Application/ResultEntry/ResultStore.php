<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Port for the transactional raw data persistence of the entered game
 * results of a fixture (R22.6, R22.7, R24.4, R35.1).
 *
 * Decouples {@see ResultRecording} from the concrete persistence technology.
 * The implementation writes one lineup row per row of the score sheet (roster
 * player – also as blind player –, ghost team row) and the net
 * results (sheet value, R27.10) with the blind flag as raw data; saving is
 * fully transactional (R4.3), so that no partial writes remain.
 *
 * The port is implemented by an adapter based on the
 * {@see \Bowlingliga\Persistence\LineupRepository} and
 * {@see \Bowlingliga\Persistence\GameResultRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface ResultStore
{
    /**
     * Saves the entered game results of a fixture transactionally as raw data
     * (R22.6, R22.7).
     *
     * Existing lineup rows and results of the fixture are replaced by the ones
     * passed, so that draft and confirmation use the same path.
     */
    public function saveResults(EnteredResults $results): void;

    /**
     * Players stored as blind players (rows with only blind games) of a team
     * in the fixture (R27.4).
     *
     * @return list<int>
     */
    public function findBlindPlayers(int $fixtureId, int $teamSeasonId): array;

    /**
     * Removes all entered game results and lineup rows of a fixture
     * transactionally (R24.4, R30.15/16 – fallback to "offen"/"in
     * Bearbeitung").
     */
    public function removeResults(int $fixtureId): void;

    /**
     * Tells whether at least one game result is currently stored for the
     * fixture (basis for the fallback of the completion status, R30.15/16).
     */
    public function hasResults(int $fixtureId): bool;

    /**
     * The stored results of the players of a fixture (without ghost team
     * rows), as the base of the history (value before a change).
     *
     * @return list<EnteredGame>
     */
    public function findGames(int $fixtureId): array;
}
