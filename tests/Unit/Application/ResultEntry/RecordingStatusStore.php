<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\FixtureStatusStore;

/**
 * In-memory fake of the {@see FixtureStatusStore} port. All fixtures belong to
 * a single matchday.
 */
final class RecordingStatusStore implements FixtureStatusStore
{
    /** @var array<int, string> per fixture the status set last */
    public array $status = [];

    /** Number of an earlier matchday that is not complete yet (R30.20). */
    public ?int $incompleteEarlierMatchday = null;

    public function setCompletionStatus(int $fixtureId, string $completionStatus): void
    {
        $this->status[$fixtureId] = $completionStatus;
    }

    public function areAllComplete(int $matchdayId): bool
    {
        return $this->status !== []
            && array_filter($this->status, static fn (string $s): bool => $s !== 'complete') === [];
    }

    public function blockingMatchdayNumber(int $fixtureId): ?int
    {
        return ($this->status[$fixtureId] ?? null) === 'complete' ? null : $this->incompleteEarlierMatchday;
    }
}
