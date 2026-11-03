<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use RuntimeException;

/**
 * Thrown if no connection to the MySQL database can be established
 * (R4.4). Signals to the calling code that read and write access
 * must be denied and an error message is to be shown.
 */
final class DatabaseConnectionException extends RuntimeException
{
}
