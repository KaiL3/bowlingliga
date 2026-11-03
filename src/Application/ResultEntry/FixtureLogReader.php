<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Reads the history of a fixture (adapter: `FixtureLogRepository`).
 */
interface FixtureLogReader
{
    /**
     * @return list<FixtureLogEntry> newest first
     */
    public function entries(int $fixtureId): array;
}
