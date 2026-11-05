<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Positionday;

use Bowlingliga\Domain\Message;

/**
 * Result of forming a positionday (R31).
 *
 * Encapsulates the two distinguishable domain outcomes explicitly so that the
 * calling use case (positionday, R26, R31) can decide without
 * resorting to exceptions or special values:
 *
 * - Ready: all matchdays before the positionday have the completion status
 *   "complete"; the fixtures were formed from the current state of the league
 *   table (first against second, third against fourth and so on, R31.2,
 *   R31.3). The result carries the ordered fixtures and no note.
 * - Not ready: at least one matchday before the positionday is not yet
 *   "complete". The fixtures are deliberately not fixed; instead the result
 *   carries a note that all previous matchdays must be entered completely
 *   first (R31.4). There are no fixtures.
 *
 * In the "not ready" case no partial fixtures are returned on purpose, so that
 * the calling use case fixes or saves nothing (R31.4).
 *
 * Pure, immutable value object without side effects.
 */
final class PositiondayResult
{
    /**
     * @param list<PositiondayFixture> $fixtures Ordered positionday fixtures (1v2, 3v4, ...); empty if not ready.
     * @param Message|null             $note     Note that all previous matchdays must be complete first (R31.4); null when ready.
     */
    private function __construct(
        public readonly array $fixtures,
        public readonly ?Message $note,
    ) {
    }

    /**
     * Ready: all previous matchdays are "complete"; the fixtures formed from
     * the standings are fixed (R31.2, R31.3).
     *
     * @param list<PositiondayFixture> $fixtures
     */
    public static function ready(array $fixtures): self
    {
        return new self($fixtures, null);
    }

    /**
     * Not ready: not all previous matchdays are "complete" yet; no fixtures are
     * fixed, a note is returned instead (R31.4).
     *
     * @throws \InvalidArgumentException If no note is given.
     */
    public static function notReady(Message $note): self
    {
        if ($note === '') {
            throw new \InvalidArgumentException('A "not ready" result must contain a notice.');
        }

        return new self([], $note);
    }

    /**
     * true if all previous matchdays are "complete" and the fixtures are fixed
     * (R31.2); false as long as there is a note about incomplete matchdays
     * (R31.4).
     */
    public function isReady(): bool
    {
        return $this->note === null;
    }
}
