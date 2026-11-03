<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Domain errors of the season and matchday management that go beyond pure
 * field validation (R8.4, R8.10, R12.7, R8.19).
 *
 * Covers among others: non-existent season/matchday, duplicate matchday
 * number, date outside the season and changes/deletions not allowed because
 * the season has already started. The message names the reason for the
 * rejection.
 */
final class SeasonAdministrationException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    public static function seasonNotFound(): self
    {
        return new self('error.season.not_found');
    }

    public static function matchdayNotFound(): self
    {
        return new self('error.season.matchday_not_found');
    }

    /**
     * Change/deletion after the season has already started (R8.10, R12.7,
     * R8.16, R8.19).
     */
    public static function seasonAlreadyStarted(): self
    {
        return new self('error.season.already_started');
    }

    /**
     * The matchday number is already taken within the season (R8.4).
     */
    public static function matchdayNumberTaken(int $number): self
    {
        return new self('error.season.matchday_number_taken', ['number' => $number]);
    }

    /**
     * The matchday date is outside the season's start/end date (R8.4).
     */
    public static function dateOutsideSeason(): self
    {
        return new self('error.season.date_outside');
    }
}
