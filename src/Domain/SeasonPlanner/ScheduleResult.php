<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\SeasonPlanner;

use Bowlingliga\Domain\Message;

/**
 * Immutable result of a call to the season planner (R9.1, R9.5, R9.12).
 *
 * The season planner works without side effects and throws no exceptions for
 * domain error cases but returns them explicitly via this result object, so
 * that the calling layer can evaluate them without resorting to special
 * values and show the administrator the intended message. Three
 * distinguishable domain states are represented:
 *
 * - Success without warning: the plan sets every pair of teams against each
 *   other exactly equally often and spreads evenly over the defined season
 *   length (R9.1, R9.11). {@see successful} is true, {@see schedule} is set,
 *   {@see warnings} is empty.
 * - Success with warning: the plan could be generated, but the defined season
 *   length does not give an even round robin distribution with an identical
 *   number of encounters per pair of teams (R9.12). {@see successful} is true,
 *   {@see schedule} is set, {@see warnings} contains the reason; applying it
 *   requires explicit confirmation by the administrator (R9.12).
 * - Error: no plan can be generated because fewer than 2 teams take part or
 *   no balanced fixture distribution is possible (R9.5). {@see successful} is
 *   false, {@see schedule} is null, {@see errorCause} names the cause; no plan
 *   is saved (R9.5).
 */
final class ScheduleResult
{
    /**
     * @param bool          $successful True if a schedule was generated (also
     *                                  with warning), false in the error case
     *                                  (R9.5, R9.12).
     * @param Schedule|null $schedule   The generated schedule on success,
     *                                  otherwise null (R9.1, R9.5).
     * @param list<Message> $warnings   Warnings about the uneven distribution
     *                                  (R9.12); empty in the regular case.
     * @param Message|null  $errorCause Cause if no plan could be generated
     *                                  (R9.5); otherwise null.
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?Schedule $schedule,
        public readonly array $warnings,
        public readonly ?Message $errorCause,
    ) {
    }

    /**
     * Successful generation with a balanced, evenly distributed round robin
     * structure without warning (R9.1, R9.11).
     */
    public static function success(Schedule $schedule): self
    {
        return new self(true, $schedule, [], null);
    }

    /**
     * Successful generation whose application, however, requires explicit
     * confirmation by the administrator because of the uneven distribution
     * (R9.12).
     *
     * @param list<Message> $warnings Not empty.
     *
     * @throws \InvalidArgumentException If no warning is given.
     */
    public static function successWithWarning(Schedule $schedule, array $warnings): self
    {
        if ($warnings === []) {
            throw new \InvalidArgumentException('A result with a warning must contain at least one warning.');
        }

        return new self(true, $schedule, $warnings, null);
    }

    /**
     * Error case: no schedule can be generated and saved; the cause is named
     * (R9.5).
     *
     * @throws \InvalidArgumentException If no cause is given.
     */
    public static function error(Message $errorCause): self
    {
        if ($errorCause->key === '') {
            throw new \InvalidArgumentException('A failed result must name a cause.');
        }

        return new self(false, null, [], $errorCause);
    }

    /**
     * Tells whether the result contains at least one warning about the uneven
     * distribution (R9.12).
     */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
