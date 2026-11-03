<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamEntry;

use Bowlingliga\Security\WriteAccess;

/**
 * Write access for the final saving of results confirmed by both teams
 * (R37.6).
 *
 * The {@see \Bowlingliga\Application\ResultEntry\ResultRecording} that saves
 * team results gets this access instead of the admin login. It is granted only
 * while {@see TeamResultEntry} performs the final saving after both teams have
 * confirmed with their codes; otherwise it denies like a missing login.
 */
final class TeamEntryWriteAccess implements WriteAccess
{
    private bool $granted = false;

    public function checkWriteAccess(): bool
    {
        return $this->granted;
    }

    /**
     * @template T
     *
     * @param callable(): T $steps
     *
     * @return T
     */
    public function grantDuring(callable $steps): mixed
    {
        $this->granted = true;
        try {
            return $steps();
        } finally {
            $this->granted = false;
        }
    }
}
