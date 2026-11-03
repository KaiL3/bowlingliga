<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Domain errors of the previous season record management that go beyond pure
 * field validation (R29.9).
 *
 * Covers the case that the season of the record has already started. The
 * message names the reason for the rejection.
 */
final class PreviousSeasonRecordManagementException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * The season has already reached its start date; its previous season
     * records can no longer be changed (R29.9).
     */
    public static function seasonAlreadyStarted(): self
    {
        return new self('error.previous_season.season_started');
    }
}
