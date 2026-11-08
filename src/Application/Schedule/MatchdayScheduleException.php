<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Schedule;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Rejected cancellation or postponement of a matchday, with the reason for the
 * admin (R8.20, R8.21).
 */
final class MatchdayScheduleException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    public static function notFound(): self
    {
        return new self('error.schedule.not_found');
    }

    public static function resultsEntered(int $number): self
    {
        return new self('error.schedule.results_entered', ['number' => $number]);
    }

    public static function alreadyCancelled(int $number): self
    {
        return new self('error.schedule.already_cancelled', ['number' => $number]);
    }

    public static function notCancelled(int $number): self
    {
        return new self('error.schedule.not_cancelled', ['number' => $number]);
    }

    public static function notInFuture(int $number): self
    {
        return new self('error.schedule.not_in_future', ['number' => $number]);
    }

    public static function laterResultsEntered(int $number, int $later): self
    {
        return new self('error.schedule.later_results_entered', ['number' => $number, 'later' => $later]);
    }

    public static function complete(int $number): self
    {
        return new self('error.schedule.complete', ['number' => $number]);
    }

    public static function notLater(int $number): self
    {
        return new self('error.schedule.not_later', ['number' => $number]);
    }

    public static function invalidDate(): self
    {
        return new self('error.schedule.invalid_date');
    }
}
