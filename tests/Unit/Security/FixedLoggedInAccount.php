<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\LoggedInAccount;

/** Fake of the {@see LoggedInAccount} port: the test sets the account. */
final class FixedLoggedInAccount implements LoggedInAccount
{
    public function __construct(public ?int $id = null)
    {
    }

    public function loggedInAccount(): ?int
    {
        return $this->id;
    }
}
