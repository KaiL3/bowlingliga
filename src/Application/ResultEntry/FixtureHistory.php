<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\WriteAccess;

/**
 * History of a fixture for the admin: every entry of the teams via their code
 * and every change of the admin, with time and value before/after.
 *
 * Only for logged-in admins: the history shows who entered what, including the
 * reasons when sending back, and must never reach the team page or the public.
 * The check here holds even if a route forgets it (R37.11).
 */
final class FixtureHistory
{
    public function __construct(
        private readonly FixtureLogReader $reader,
        private readonly WriteAccess $adminAccess,
    ) {
    }

    /**
     * @return list<FixtureLogEntry> newest first
     *
     * @throws AccessDeniedException without an admin login
     */
    public function entries(int $fixtureId): array
    {
        if (! $this->adminAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }

        return $this->reader->entries($fixtureId);
    }
}
