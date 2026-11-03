<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PublicSite;

use Bowlingliga\Domain\Table\Table;

/**
 * Prepared detail view of a played matchday (R15.1, R2.1, R15.3, R15.4).
 *
 * A matchday comprises one or more fixtures. Each fixture
 * ({@see MatchdayFixture}) is shown as its own tab in the detail view – named
 * after the lane pair – and contains the digital score sheet view of both
 * teams and their average lists (R2.1, R22.1). The matchday table (R15.3)
 * applies to the whole matchday and is therefore kept once, not per fixture.
 *
 * Pure, immutable value object; the visibility check (only played matchdays,
 * R15.5) happens in the calling use case.
 */
final class MatchdayDetail
{
    /**
     * @param int                   $matchdayId    Matchday id
     * @param int                   $number        Matchday counter
     * @param string                $date          Date (Y-m-d)
     * @param list<MatchdayFixture> $fixtures      Fixtures of the matchday (R15.1)
     * @param Table                 $matchdayTable matchday table for the whole matchday (R15.3)
     */
    public function __construct(
        public readonly int $matchdayId,
        public readonly int $number,
        public readonly string $date,
        public readonly array $fixtures,
        public readonly Table $matchdayTable,
    ) {
    }

    /**
     * Tells whether the matchday contains at least one fixture.
     */
    public function hasFixtures(): bool
    {
        return $this->fixtures !== [];
    }
}
