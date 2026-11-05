<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Points;

use InvalidArgumentException;

/**
 * Bonus points scale of a season (R17.6–R17.8, R17.11): up to the threshold
 * 1 bonus point, above it 2 points plus one more per further full step, at
 * most the maximum. Defaults 2100 / 100 / 6, i.e. up to 2100 → 1,
 * 2101–2200 → 2, … 2401–2500 → 5, from 2501 → 6.
 *
 * Value object: immutable, without side effects.
 */
final class BonusPointsScale
{
    public const DEFAULT_THRESHOLD = 2100;

    public const DEFAULT_STEP = 100;

    public const DEFAULT_MAXIMUM = 6;

    /** Upper bound of the threshold (above any reachable gross of an evening). */
    public const THRESHOLD_MAX = 9999;

    public const STEP_MAX = 1000;

    public const MAXIMUM_MAX = 20;

    /**
     * @param int $threshold Gross total up to which exactly 1 bonus point is awarded, 0–9999.
     * @param int $step      Pins per further bonus point above the threshold, 1–1000.
     * @param int $maximum   Highest number of bonus points, 0–20 (0 = no bonus points).
     *
     * @throws InvalidArgumentException for a value outside its range.
     */
    public function __construct(
        public readonly int $threshold = self::DEFAULT_THRESHOLD,
        public readonly int $step = self::DEFAULT_STEP,
        public readonly int $maximum = self::DEFAULT_MAXIMUM,
    ) {
        if ($threshold < 0 || $threshold > self::THRESHOLD_MAX
            || $step < 1 || $step > self::STEP_MAX
            || $maximum < 0 || $maximum > self::MAXIMUM_MAX) {
            throw new InvalidArgumentException('Invalid bonus points scale.');
        }
    }

    public static function standard(): self
    {
        return new self();
    }

    /**
     * Bonus points for the gross total of an evening (R17.5–R17.8).
     *
     * @throws InvalidArgumentException for a negative gross total.
     */
    public function points(int $grossTotal): int
    {
        if ($grossTotal < 0) {
            throw new InvalidArgumentException('The gross total must not be negative.');
        }

        $points = $grossTotal <= $this->threshold
            ? 1
            : 2 + intdiv($grossTotal - $this->threshold - 1, $this->step);

        return min($points, $this->maximum);
    }

    /**
     * The levels of the scale for a legend, lowest first: `from` null means
     * "up to `to`", `to` null means "from `from`" (R17.11).
     *
     * @return list<array{from: int|null, to: int|null, points: int}>
     */
    public function levels(): array
    {
        if ($this->maximum === 0) {
            return [];
        }
        if ($this->maximum === 1) {
            return [['from' => 0, 'to' => null, 'points' => 1]];
        }

        $levels = [['from' => null, 'to' => $this->threshold, 'points' => 1]];
        for ($points = 2; $points < $this->maximum; $points++) {
            $from = $this->threshold + ($points - 2) * $this->step + 1;
            $levels[] = ['from' => $from, 'to' => $from + $this->step - 1, 'points' => $points];
        }
        $levels[] = ['from' => $this->threshold + ($this->maximum - 2) * $this->step + 1, 'to' => null, 'points' => $this->maximum];

        return $levels;
    }
}
