<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\Translatable;

/**
 * Signals that the logged-in account lacks a permission (R23.10, R23.12).
 * Nothing is changed.
 */
final class PermissionDeniedException extends \RuntimeException implements Translatable
{
    use CarriesMessage {
        CarriesMessage::__construct as private carryMessage;
    }

    public function __construct(public readonly Permission $permission)
    {
        $this->carryMessage('error.permission_denied', ['permission' => new Message($permission->label())]);
    }
}
