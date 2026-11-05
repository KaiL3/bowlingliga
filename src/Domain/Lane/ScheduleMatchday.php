<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Lane;

use InvalidArgumentException;

/**
 * A matchday of the schedule as input to the lane assignment (requirement
 * 18.1, 18.7).
 *
 * Carries the 1-based matchday number and the fixtures fixed for this
 * matchday. Matchdays whose fixtures are only fixed later (in particular
 * positiondays according to R31) are either passed with an empty
 * fixture list or only fed in once their fixtures are fixed; the lane
 * assignment service assigns them by the same even distribution principle
 * (R18.7).
 *
 * Deliberately kept as a generic structure to keep the lane assignment
 * service independent of the concrete classes of the season planner.
 *
 * Pure, immutable value object without side effects.
 */
final class ScheduleMatchday
{
    /** @var list<Fixture> */
    public readonly array $fixtures;

    /**
     * @param int           $number   1-based matchday number.
     * @param list<Fixture> $fixtures Fixed fixtures of this matchday.
     *
     * @throws InvalidArgumentException for a non-positive number or invalid fixtures.
     */
    public function __construct(
        public readonly int $number,
        array $fixtures
    ) {
        if ($number < 1) {
            throw new InvalidArgumentException('The matchday number must be at least 1.');
        }

        $normalised = [];
        foreach ($fixtures as $fixture) {
            if (!$fixture instanceof Fixture) {
                throw new InvalidArgumentException('Every fixture must be a ' . Fixture::class . ' sein.');
            }
            $normalised[] = $fixture;
        }

        $this->fixtures = $normalised;
    }

    /**
     * Number of fixtures to assign on this matchday.
     */
    public function fixtureCount(): int
    {
        return count($this->fixtures);
    }
}
