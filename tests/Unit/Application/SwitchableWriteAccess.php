<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Security\WriteAccess;

/**
 * Switchable write access stub for the unit tests of the use cases.
 *
 * Allows or denies write access depending on a flag, without a real session
 * or authentication. Shared by several management tests and therefore kept in
 * its own file, so that it is not declared more than once.
 */
final class SwitchableWriteAccess implements WriteAccess
{
    public function __construct(public bool $allowed)
    {
    }

    public function checkWriteAccess(): bool
    {
        return $this->allowed;
    }
}
