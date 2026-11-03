<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Signals that a writing function was called without a valid authenticated
 * session (R5.1).
 *
 * In this case the writing action is not executed and the data concerned
 * stays unchanged; the calling (web) context asks the user to log in.
 */
final class AccessDeniedException extends \RuntimeException implements Translatable
{
    use CarriesMessage {
        CarriesMessage::__construct as private carryMessage;
    }

    public function __construct()
    {
        $this->carryMessage('error.login_required');
    }
}
