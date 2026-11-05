<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Lane;

use Bowlingliga\Domain\Message;

/**
 * Result of a lane assignment (R18).
 *
 * Encapsulates the three distinguishable domain outcomes explicitly so that
 * the calling use case (season planning/positionday, R9, R31) can
 * decide without resorting to exceptions or special values:
 *
 * - Success (without warning): for every fixture of every matchday there is
 *   exactly one {@see LaneAssignment} (R18.1), and for every team
 *   the best possible even distribution with a lane pair spread of at most one
 *   matchday is reached (R18.2, first case). There is no error
 *   message and no warning.
 * - Success with warning: there is an assignment for every fixture, which is
 *   likewise calculated and saved; for at least one team, however, a spread
 *   of at most one matchday cannot be reached (R18.2, second
 *   case). In this case the result carries a warning about the not fully even
 *   distribution (R18.9) and an overview of the deviations per
 *   affected team – its usage per lane pair and the resulting spread
 *   (R18.10).
 * - Failure: there is no assignment but an error message pointing to the
 *   missing prerequisite – missing lane count or missing schedule
 *   (R18.5) or insufficient lane pairs (R18.6).
 *
 * On failure no partial assignment is returned on purpose, so that the
 * calling use case saves nothing (R18.5, R18.6).
 *
 * Pure, immutable value object without side effects.
 */
final class LaneAssignmentResult
{
    /**
     * @param list<LaneAssignment>                                          $assignments Assigned lane pairs per fixture/matchday.
     * @param Message|null                                                 $error       Error message or null on success.
     * @param Message|null                                                 $warning     Warning about the not fully even distribution (R18.9) or null.
     * @param array<int, array{usage: array<int, int>, spread: int}> $deviations  Overview of the deviations per affected team (R18.10); empty if there is no warning.
     */
    private function __construct(
        public readonly array $assignments,
        public readonly ?Message $error,
        public readonly ?Message $warning = null,
        public readonly array $deviations = []
    ) {
    }

    /**
     * Successful assignment with a complete lane pair assignment and the best
     * possible even distribution (spread ≤ 1 per team) without warning
     * (R18.1, R18.2 first case).
     *
     * @param list<LaneAssignment> $assignments
     */
    public static function success(array $assignments): self
    {
        return new self($assignments, null, null, []);
    }

    /**
     * Successful assignment for which, however, no even distribution with a
     * spread of at most one matchday per team was reachable; the assignment is
     * still calculated and returned, together with a warning (requirement
     * 18.9) and an overview of the deviations per affected team (requirement
     * 18.10).
     *
     * @param list<LaneAssignment>                                          $assignments
     * @param Message                                                      $warning     Not empty.
     * @param array<int, array{usage: array<int, int>, spread: int}> $deviations  Not empty.
     *
     * @throws \InvalidArgumentException If no warning or no deviations are given.
     */
    public static function successWithWarning(array $assignments, Message $warning, array $deviations): self
    {
        if ($warning === '') {
            throw new \InvalidArgumentException('A result with a warning must contain a warning.');
        }
        if ($deviations === []) {
            throw new \InvalidArgumentException('A result with a warning must contain an overview of the deviations.');
        }

        return new self($assignments, null, $warning, $deviations);
    }

    /**
     * Failure without assignment, with an error message pointing to the
     * missing prerequisite (R18.5, R18.6).
     */
    public static function error(Message $message): self
    {
        return new self([], $message, null, []);
    }

    /**
     * true if a complete lane assignment exists (R18.1), regardless
     * of whether the distribution entails a warning.
     */
    public function isSuccessful(): bool
    {
        return $this->error === null;
    }

    /**
     * true if the (successful) assignment carries a warning about the not fully
     * even distribution (R18.9, R18.10).
     */
    public function hasWarning(): bool
    {
        return $this->warning !== null;
    }
}
