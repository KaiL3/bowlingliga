<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\SeasonPlanner;

/**
 * Immutable value object of a fixture of a matchday: the assignment of exactly
 * two teams to a matchday (R9.1, R9.2).
 *
 * A fixture always connects exactly two different teams. If the season
 * contains a ghost team (R9.6), exactly one of the two teams can be the ghost
 * team; the season planner ensures that at most one fixture per matchday
 * contains the ghost team (R9.7).
 *
 * The order (home/away) is taken deterministically from the round algorithm
 * and has no domain meaning for the pure round robin structure; the lane
 * assignment according to R18 happens in a later service.
 */
final class Fixture
{
    /**
     * @param Team $home First team of the fixture. Not identical with {$away}.
     * @param Team $away Second team of the fixture. Not identical with {$home}.
     *
     * @throws \InvalidArgumentException If both teams carry the same id.
     */
    public function __construct(
        public readonly Team $home,
        public readonly Team $away,
    ) {
        if ($home->id === $away->id) {
            throw new \InvalidArgumentException(
                'A fixture must join two different teams, given twice: ' . $home->id
            );
        }
    }

    /**
     * Tells whether this fixture contains the ghost team (R9.6, R9.7).
     */
    public function containsGhostTeam(): bool
    {
        return $this->home->isGhostTeam || $this->away->isGhostTeam;
    }

    /**
     * Checks whether the team with the given id takes part in this fixture
     * (R9.2).
     */
    public function containsTeam(string $teamId): bool
    {
        return $this->home->id === $teamId || $this->away->id === $teamId;
    }
}
