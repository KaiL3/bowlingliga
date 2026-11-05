<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Lane;

use InvalidArgumentException;

/**
 * Minimal fixture as input to the lane assignment (R18.1, R18.8).
 *
 * Deliberately kept independent of the SeasonPlanner: the lane assignment
 * only needs the identity of the two teams involved and – for requirement
 * 18.8 – whether one of the two teams is the ghost team according to
 * R20. This keeps the lane assignment service testable on its own
 * and lets it accept a generic schedule instead of requiring concrete classes
 * of the season planner.
 *
 * R18.8: a fixture of a real team and the ghost team is treated
 * like any other; the ghost team takes a regular lane pair. The ghost team
 * property is therefore irrelevant for the assignment, but the ghost team is
 * included in the even distribution like any other team.
 *
 * Pure, immutable value object without side effects.
 */
final class Fixture
{
    /**
     * @param int  $homeTeamId  Identity of the home team (or ghost team).
     * @param int  $awayTeamId  Identity of the away team (or ghost team).
     * @param bool $homeIsGhost true if the home team is the ghost team (R18.8, R20).
     * @param bool $awayIsGhost true if the away team is the ghost team (R18.8, R20).
     *
     * @throws InvalidArgumentException if both teams are identical.
     */
    public function __construct(
        public readonly int $homeTeamId,
        public readonly int $awayTeamId,
        public readonly bool $homeIsGhost = false,
        public readonly bool $awayIsGhost = false
    ) {
        if ($homeTeamId === $awayTeamId) {
            throw new InvalidArgumentException('A fixture must consist of two different teams.');
        }
    }

    /**
     * Team identities of the fixture; both count equally towards the even
     * distribution per lane pair (R18.2, R18.8).
     *
     * @return array{0: int, 1: int}
     */
    public function teamIds(): array
    {
        return [$this->homeTeamId, $this->awayTeamId];
    }
}
