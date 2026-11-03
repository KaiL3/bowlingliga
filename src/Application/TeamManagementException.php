<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Domain errors of the team management that are not pure field validation
 * (R7.3, R7.5, R7.7).
 *
 * Covers the cases of a duplicate team name per season (R7.3), a non-existent
 * team (R7.5) and removing from an already started season (R7.7). The message
 * is phrased for the user and names the reason for the rejection.
 */
final class TeamManagementException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * Doppelter Teamname innerhalb derselben Saison (R7.3).
     */
    public static function duplicateTeamName(string $teamLabel): self
    {
        return new self('error.team.duplicate_name', ['team' => $teamLabel]);
    }

    /**
     * A non-existent team is to be removed (R7.5).
     */
    public static function teamNotFound(): self
    {
        return new self('error.team.not_found');
    }

    /**
     * A team season assignment was not found.
     */
    public static function assignmentNotFound(): self
    {
        return new self('error.team.assignment_not_found');
    }

    /**
     * Removing from an already started season is not allowed (R7.7).
     */
    public static function seasonAlreadyStarted(): self
    {
        return new self('error.team.season_started');
    }
}
