<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Positionday;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\Translatable;

/**
 * Domain errors of the positionday wiring (R31.5, R31.7).
 *
 * Covers: an already completed positionday (no (re)formation allowed any
 * more, R31.7), a failed lane assignment (R31.5/R18.5/18.6) and failed
 * saving. The message names the reason.
 */
final class PositiondayException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * The positionday is already "complete"; the fixtures may no longer be
     * reformed or corrected (R31.7).
     */
    public static function alreadyComplete(): self
    {
        return new self('error.positionday.already_complete');
    }

    /**
     * The lane assignment of the positionday could not be generated (R31.5,
     * R18.5, R18.6).
     */
    public static function laneAssignmentFailed(Message $cause): self
    {
        return new self($cause->key, $cause->params);
    }

    /**
     * Saving the positionday fixtures failed; the data stays unchanged.
     */
    public static function saveFailed(\Throwable $before): self
    {
        return new self('error.positionday.save_failed', [], $before);
    }
}
