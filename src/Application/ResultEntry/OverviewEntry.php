<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * An entry of the result entry overview: a fixture with its completion status
 * (R30.1–R30.4, R30.19).
 *
 * Pure, immutable value object. Whether a fixture still has to be filled in
 * (R30.19) follows from the status "offen" or "in Bearbeitung".
 */
final class OverviewEntry
{
    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETE = 'complete';

    /**
     * @param int         $fixtureId       Fixture id
     * @param int         $matchdayId      Matchday id
     * @param int         $matchdayCounter sequential number of the matchday
     * @param string|null $homeName        Team name home (or null if open)
     * @param string|null $awayName        Team name away (or null if open)
     * @param 'open'|'in_progress'|'complete' $completionStatus Status (R30.2–4)
     * @param string|null $date            Date of the matchday (Y-m-d), if known
     * @param string|null $lanes           Lane pair as "1/2", if assigned
     */
    public function __construct(
        public readonly int $fixtureId,
        public readonly int $matchdayId,
        public readonly int $matchdayCounter,
        public readonly ?string $homeName,
        public readonly ?string $awayName,
        public readonly string $completionStatus,
        public readonly ?string $date = null,
        public readonly ?string $lanes = null,
    ) {
    }

    /**
     * Tells whether the fixture still has to be filled in (R30.19): status
     * "offen" or "in Bearbeitung".
     */
    public function isToBeFilled(): bool
    {
        return $this->completionStatus === self::OPEN
            || $this->completionStatus === self::IN_PROGRESS;
    }
}
