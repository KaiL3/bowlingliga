<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Domain errors of the player management for roster assignment (R6.7, R6.8).
 *
 * A player belongs to at most one roster per season; changing teams within
 * the season is not intended. The messages are phrased for the user and name
 * the existing team.
 */
final class PlayerManagementException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * The player is already on this team's roster (R6.7).
     */
    public static function alreadyInRoster(string $playerLabel): self
    {
        return new self('error.player.already_in_roster', ['player' => $playerLabel]);
    }

    /**
     * The player is already on another team's roster in this season (R6.8).
     */
    public static function playsForOtherTeam(string $playerLabel, string $teamLabel): self
    {
        return new self('error.player.plays_for_other_team', ['player' => $playerLabel, 'team' => $teamLabel]);
    }
}
