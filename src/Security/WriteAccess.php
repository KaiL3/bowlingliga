<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port for checking write access for writing use cases (R5.1, R5.4).
 *
 * Decouples the writing management services from the concrete
 * authentication: they only know the domain question "may we write right
 * now?". The port is implemented by the {@see AuthModule} (method
 * {@see AuthModule::checkWriteAccess()}); in tests a slim fake is enough.
 */
interface WriteAccess
{
    /**
     * Tells whether there currently is a valid, not expired authenticated
     * session and thus write access is allowed (R5.1, R5.7).
     */
    public function checkWriteAccess(): bool;
}
