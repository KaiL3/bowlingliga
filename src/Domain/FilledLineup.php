<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * Result of filling a team lineup with blind players for an evening
 * (R27.1–3, R21.3, R21.6).
 *
 * Contains exactly 4 positions (R27.1). Each position is either a regularly
 * lined-up real player or a blind player. The special case "no real player"
 * (R27.3, R21.6) is represented explicitly via {@see $lost} and
 * {@see $points} and is then kept without blind players.
 *
 * Pure value object without side effects; the actual filling happens in
 * {@see BlindPlayerService::fillLineup()}.
 */
final class FilledLineup
{
    /**
     * @param list<LineupPosition> $positions exactly 4 positions (R27.1),
     *        except in the special case "no real player" (empty, R27.3)
     * @param bool $lost true if the team fields no real player and the evening
     *        counts as lost (R27.3, R21.6)
     * @param int|null $points fixed points; 0 in the special case "no real
     *        player" (R27.3, R21.6), otherwise null – regular points are
     *        awarded by the points service (R17, R27.12)
     */
    public function __construct(
        public readonly array $positions,
        public readonly bool $lost,
        public readonly ?int $points
    ) {
    }

    /**
     * Number of blind player positions in the lineup.
     */
    public function blindPlayerCount(): int
    {
        return count(array_filter(
            $this->positions,
            static fn (LineupPosition $p): bool => $p->isBlind
        ));
    }

    /**
     * Number of real player positions in the lineup.
     */
    public function realPlayerCount(): int
    {
        return count(array_filter(
            $this->positions,
            static fn (LineupPosition $p): bool => ! $p->isBlind
        ));
    }
}
