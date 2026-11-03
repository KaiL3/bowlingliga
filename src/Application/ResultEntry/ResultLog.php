<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Records the admin's changes to the results of a fixture in its history
 * (adapter: `FixtureLogRepository`, which adds the admin account).
 */
interface ResultLog
{
    /**
     * One changed value: net, optionally marked " blind"; null = none.
     */
    public function changed(int $fixtureId, int $playerId, int $gameNumber, ?string $before, ?string $after): void;

    /**
     * A step without a single value (saved as draft, confirmed, removed …).
     */
    public function noted(int $fixtureId, string $action, string $detail = ''): void;
}
