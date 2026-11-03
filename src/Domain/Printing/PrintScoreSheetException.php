<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Printing;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Domain error of the printed score sheet service (R19.5).
 *
 * Thrown if there is no lane assignment yet for a regular matchday or a
 * positionday; the printed score sheet is then not generated and the
 * administrator is pointed to the lane assignment needed first. For a lot
 * draw the missing lane number is the normal case and causes no error (R19.5,
 * R32.3).
 */
final class PrintScoreSheetException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * Missing lane assignment for a regular matchday or positionday (R19.5).
     */
    public static function laneAssignmentMissing(): self
    {
        return new self('error.print.no_lane_assignment');
    }

    /**
     * There are no fixtures for the selected matchday (e.g. a positionday whose
     * fixtures have not been formed yet).
     */
    public static function noFixtures(): self
    {
        return new self('error.print.no_fixtures');
    }
}
