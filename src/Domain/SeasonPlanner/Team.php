<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\SeasonPlanner;

/**
 * Immutable value object of a team taking part in the season planning (R9.1).
 *
 * The season planner is a pure calculation unit without side effects: it
 * receives the participating teams as input and assigns them to the matchdays
 * in round robin mode. A team is described here only by its stable technical
 * id and its display name; all other domain details are irrelevant for the
 * schedule generation.
 *
 * With an odd number of real teams the season planner itself adds an extra
 * ghost team (R9.6); such a ghost team is marked via {@see isGhostTeam} and
 * created via {@see ghostTeam()}. As input the season planner expects only
 * real teams.
 */
final class Team
{
    /**
     * @param string $id          Stable technical id of the team; lets the
     *                            caller attribute the generated fixtures. Not
     *                            empty.
     * @param string $name        The display name of the team defined per
     *                            season (R9.1). Not empty.
     * @param bool   $isGhostTeam True if this team is the ghost team added by
     *                            the season planner (R9.6).
     *
     * @throws \InvalidArgumentException If the id or the name is empty.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $isGhostTeam = false,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('The id of a team must not be empty.');
        }

        if ($name === '') {
            throw new \InvalidArgumentException('The name of a team must not be empty.');
        }
    }

    /**
     * Creates the ghost team added automatically by the season planner for an
     * odd number of real teams (R9.6).
     */
    public static function ghostTeam(string $id = 'geisterteam', string $name = 'Geisterteam'): self
    {
        return new self($id, $name, true);
    }
}
