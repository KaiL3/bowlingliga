<?php

declare(strict_types=1);

namespace Bowlingliga\Application\SeasonPlanning;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\Translatable;

/**
 * Domain errors of the season planning wiring (R9.4, R9.5, R18.5, R18.6).
 *
 * Covers among others: failed saving (R9.4), a plan that cannot be generated
 * (R9.5), missing configuration/lane assignment (R18.5/18.6) and the missing
 * confirmation for a distribution warning (R9.12). The message names the
 * reason.
 */
final class SeasonPlanningException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * The schedule could not be generated (R9.5).
     */
    public static function planCannotBeGenerated(Message $cause): self
    {
        return new self($cause->key, $cause->params);
    }

    /**
     * The lane assignment could not be generated (R18.5, R18.6).
     */
    public static function laneAssignmentFailed(Message $cause): self
    {
        return new self($cause->key, $cause->params);
    }

    /**
     * Applying was attempted without the explicit confirmation required for
     * warnings (R9.12).
     */
    public static function confirmationRequired(): self
    {
        return new self('error.planning.confirmation_required');
    }

    /**
     * Saving the schedule failed; the data stays unchanged (R9.4).
     */
    public static function saveFailed(\Throwable $before): self
    {
        return new self('error.planning.save_failed', [], $before);
    }
}
