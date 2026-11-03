<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Preview of a handicap chain recalculation (R12.18).
 *
 * Bundles the recalculated handicaps of all matchdays from a start matchday
 * onwards in chronological order as an old/new diff. The preview is
 * deliberately **without persistence**: it lets the administrator review the
 * affected changes; only the explicit apply
 * ({@see HandicapFreezeService::applyHandicapChainPreview()}) writes the new
 * values as raw data (R12.18/R12.19).
 *
 * @see R12.18, R12.19
 */
final class HandicapChainPreview
{
    /**
     * @param int                       $fromMatchdayCounter Start matchday of the
     *                                                       recalculation
     *                                                       (sequential number
     *                                                       within the season,
     *                                                       R12.18).
     * @param list<HandicapChainChange> $changes             All considered
     *                                                       entries in
     *                                                       chronological order.
     */
    public function __construct(
        public readonly int $fromMatchdayCounter,
        public readonly array $changes,
    ) {
    }

    /**
     * Returns all entries (chronologically), regardless of whether they change
     * the frozen value.
     *
     * @return list<HandicapChainChange>
     */
    public function all(): array
    {
        return $this->changes;
    }

    /**
     * Returns only the entries whose frozen value changes through the
     * recalculation (diff view for the administrator, R12.18).
     *
     * @return list<HandicapChainChange>
     */
    public function changesOnly(): array
    {
        return array_values(array_filter(
            $this->changes,
            static fn (HandicapChainChange $a): bool => $a->isChange()
        ));
    }

    /**
     * Tells whether the recalculation yields any change at all compared to the
     * previous state.
     */
    public function hasChanges(): bool
    {
        return $this->changesOnly() !== [];
    }

    /**
     * Fingerprint of the changes: applying recalculates the preview and only
     * applies it if the fingerprint matches the displayed preview (R12.18,
     * R12.19).
     */
    public function fingerprint(): string
    {
        $parts = [(string) $this->fromMatchdayCounter];
        foreach ($this->changesOnly() as $a) {
            $parts[] = implode(':', [$a->matchdayId, $a->playerId, $a->previousHandicap ?? '-', $a->newHandicap ?? '-']);
        }

        return hash('sha256', implode('|', $parts));
    }
}
