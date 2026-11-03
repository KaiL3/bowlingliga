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

    public function setCompletionStatus(int $fixtureId, string $completionStatus): void
    {
        $this->status[$fixtureId] = $completionStatus;
    }

    public function areAllComplete(int $matchdayId): bool
    {
        return $this->status !== []
            && array_filter($this->status, static fn (string $s): bool => $s !== 'complete') === [];
    }
}
