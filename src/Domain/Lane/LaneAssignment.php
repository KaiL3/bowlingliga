<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Lane;

/**
 * Assignment of exactly one lane pair to exactly one fixture of a matchday
 * (R18.1).
 *
 * The result atom of the lane assignment: which matchday, which fixture (via
 * its 0-based index within the matchday) and which lane pair. The fixture
 * itself is carried along so that later steps (e.g. the printed score sheet
 * according to R19) can access the teams involved without going
 * back to the input plan.
 *
 * Pure, immutable value object without side effects.
 */
final class LaneAssignment
{
    /**
     * @param int      $matchdayNumber 1-based matchday number.
     * @param int      $fixtureIndex   0-based index of the fixture in the matchday.
     * @param Fixture  $fixture        The assigned fixture.
     * @param LanePair $lanePair       The assigned lane pair.
     */
    public function __construct(
        public readonly int $matchdayNumber,
        public readonly int $fixtureIndex,
        public readonly Fixture $fixture,
        public readonly LanePair $lanePair
    ) {
    }
}
