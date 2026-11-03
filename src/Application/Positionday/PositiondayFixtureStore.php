<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Positionday;

/**
 * Port for transactionally persisting the formed positionday fixtures
 * including the lane assignment (R31.2, R31.5).
 *
 * Decouples {@see PositiondayManagement} from the concrete persistence
 * technology. The implementation replaces the (initially empty) fixtures of
 * the positionday with the fixtures formed from the standings and records the
 * lane assignment; the whole operation runs in one transaction (R4.3), so
 * that no partial writes remain.
 *
 * The port is implemented by an adapter based on the fixture and lane pair
 * repositories; in tests a fake is enough.
 */
interface PositiondayFixtureStore
{
    /**
     * Saves the formed positionday fixtures with team season assignment and
     * lane pair index transactionally (R31.2, R31.5).
     *
     * @param list<SeededPositiondayFixture> $fixtures
     */
    public function saveFixtures(int $matchdayId, array $fixtures): void;
}
