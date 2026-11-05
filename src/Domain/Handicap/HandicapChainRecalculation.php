<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Pure, side-effect-free recalculation of the handicap chain (R12.18).
 *
 * After a past matchday has been corrected, an administrator can explicitly
 * trigger a recalculation of the handicap chain from the corrected matchday
 * onwards (R12.18). This module recalculates – solely from the inputs passed
 * to it – the frozen handicaps of all matchdays from the start matchday in
 * chronological order and returns a {@see HandicapChainPreview} (old/new diff)
 * **without** any persistence.
 *
 * The actual handicap calculation deliberately stays in the pure
 * {@see HandicapModule}; this module only orchestrates selection (from the
 * start matchday), chronological sorting and diffing. For the same inputs it
 * always returns the same result (determinism, R12.18; property 6).
 *
 * Persisting only happens after explicit confirmation via
 * {@see HandicapFreezeService::applyHandicapChainPreview()} (R12.18/R12.19).
 *
 * @see R12.18, R12.19
 */
final class HandicapChainRecalculation
{
    public function __construct(
        private readonly HandicapModule $handicapModule,
    ) {
    }

    /**
     * Recalculates the handicap chain from a start matchday and returns a
     * preview (old/new diff) without persisting (R12.18).
     *
     * Only matchdays with `matchdayCounter >= $fromMatchdayCounter` are
     * included; all earlier matchdays stay untouched (R12.17). The chain is
     * processed deterministically in chronological order (by matchday counter,
     * ties by player id), so identical inputs always give the same preview
     * (R12.18; property 6).
     *
     * @param int                         $fromMatchdayCounter Start matchday of
     *                                                         the recalculation
     *                                                         (sequential number
     *                                                         within the season).
     * @param list<HandicapChainMatchday> $chain               All handicap states
     *                                                         of the chain to
     *                                                         freeze (earlier ones
     *                                                         are allowed and
     *                                                         ignored).
     *
     * @throws \InvalidArgumentException If the start matchday is less than 1.
     */
    public function recalculateHandicapChain(int $fromMatchdayCounter, array $chain): HandicapChainPreview
    {
        if ($fromMatchdayCounter < 1) {
            throw new \InvalidArgumentException(
                'The first matchday of the recalculation must be at least 1, given: ' . $fromMatchdayCounter
            );
        }

        // Only consider matchdays from the start matchday (R12.17: earlier ones stay
        // unchanged).
        $affected = array_values(array_filter(
            $chain,
            static fn (HandicapChainMatchday $s): bool => $s->matchdayCounter >= $fromMatchdayCounter
        ));

        // Deterministic chronological order (R12.18): primarily by
        // matchday counter, ties stably by player id.
        usort(
            $affected,
            static function (HandicapChainMatchday $a, HandicapChainMatchday $b): int {
                return [$a->matchdayCounter, $a->playerId] <=> [$b->matchdayCounter, $b->playerId];
            }
        );

        $changes = [];
        foreach ($affected as $entry) {
            $result = $this->handicapModule->calculateHandicap(
                $entry->average,
                $entry->targetScore,
                $entry->formula,
                $entry->maxHandicap,
                $entry->isFirstMatchday,
            );

            // Only a regularly calculated handicap carries a new value; the
            // error and special cases (R12.13/R12.14/R12.16) yield no handicap.
            $newHandicap = $result->hasHandicap() ? $result->handicap() : null;

            $changes[] = new HandicapChainChange(
                $entry->matchdayId,
                $entry->playerId,
                $result->status(),
                $entry->previousHandicap,
                $newHandicap,
            );
        }

        return new HandicapChainPreview($fromMatchdayCounter, $changes);
    }
}
