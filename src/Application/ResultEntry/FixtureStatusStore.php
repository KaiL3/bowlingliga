<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Port for setting the completion status of a fixture (R30.2–4, R30.15,
 * R30.18).
 *
 * Decouples {@see ResultRecording} from the fixture persistence. The port is
 * implemented by the {@see \Bowlingliga\Persistence\FixtureRepository}; in
 * tests a slim in-memory fake is enough.
 */
interface FixtureStatusStore
{
    /**
     * Sets the completion status of a fixture (R30.2–4).
     *
     * @param 'open'|'in_progress'|'complete' $completionStatus
     */
    public function setCompletionStatus(int $fixtureId, string $completionStatus): void;

    /**
     * Tells whether all fixtures of the matchday have the completion status
     * "complete" (R24.1). A matchday without fixtures or with a fixture not
     * fixed yet is not complete.
     */
    public function areAllComplete(int $matchdayId): bool;

    /**
     * Number of the first earlier matchday of the season that is not complete
     * yet and therefore blocks confirming the fixture (R30.20), or null. A
     * fixture already complete is never blocked: its correction stays
     * possible.
     */
    public function blockingMatchdayNumber(int $fixtureId): ?int;
}
