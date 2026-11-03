<?php

declare(strict_types=1);

namespace Bowlingliga\Application\SeasonPlanning;

/**
 * A matchday of the season planning to save, with its fixtures (R9.3, R9.9,
 * R9.13).
 *
 * Serves as a technology-neutral transfer structure between the use case
 * {@see SeasonPlanningManagement} and the {@see SeasonPlanStore}. A
 * positionday (R9.13) has a number and the type, but no fixed fixtures and no
 * lane assignment yet (R9.9, R18.7); its fixtures are only formed later.
 *
 * Pure, immutable value object.
 */
final class PlannedMatchday
{
    /**
     * @param int                     $number   Matchday counter (1-based)
     * @param 'regular'|'positionday'|'lot_draw' $type Matchday type (R9.13, R32)
     * @param list<PlannedFixture>    $fixtures fixed fixtures (empty for a
     *        positionday or lot draw, R9.9)
     */
    public function __construct(
        public readonly int $number,
        public readonly string $type,
        public readonly array $fixtures,
    ) {
    }

    public function isPositionday(): bool
    {
        return $this->type === 'positionday';
    }
}
