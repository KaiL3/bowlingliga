<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Handicap formula configurable per season (R12.1).
 *
 * For security reasons **no** freely interpretable string is evaluated (no
 * `eval`). Instead only the linear formula given by R12.8 is
 * supported:
 *
 *     HDC = (target score − average) × factor
 *
 * The factor is configurable (default `0.8`). Unknown or invalid formula
 * identifiers yield no valid formula and are treated as an error case by the
 * handicap module (R12.13/R12.16).
 *
 * @see R12.1, R12.8
 */
final class HandicapFormula
{
    /** Identifier of the default formula according to R12.8. */
    public const STANDARD = 'standard';

    /** Default factor of the default formula according to R12.8. */
    public const DEFAULT_FACTOR = 0.8;

    /**
     * @param float $factor Multiplier of the difference (target score − average).
     */
    private function __construct(
        private readonly float $factor,
    ) {
    }

    /**
     * Creates the default formula `HDC = (target score − average) × 0.8` (R12.8).
     */
    public static function standard(): self
    {
        return new self(self::DEFAULT_FACTOR);
    }

    /**
     * Creates the default formula with a different factor configured per
     * season (R12.1). The factor must be finite.
     *
     * @throws \InvalidArgumentException if the factor is not a finite value.
     */
    public static function withFactor(float $factor): self
    {
        if (!is_finite($factor)) {
            throw new \InvalidArgumentException('The handicap factor must be a finite value.');
        }

        return new self($factor);
    }

    /**
     * Creates a formula from a known identifier.
     *
     * Only {@see self::STANDARD} is supported. Unknown identifiers return
     * `null` so that the handicap module can treat the case as a missing or
     * invalid formula (R12.13).
     *
     * @param string     $identifier Formula identifier (e.g. "standard").
     * @param float|null $factor     Optional factor configured per season.
     */
    public static function fromIdentifier(string $identifier, ?float $factor = null): ?self
    {
        if (strtolower(trim($identifier)) !== self::STANDARD) {
            return null;
        }

        if ($factor === null) {
            return self::standard();
        }

        if (!is_finite($factor)) {
            return null;
        }

        return new self($factor);
    }

    /**
     * Applies the formula to target score and average and returns the
     * unrounded, unlimited raw result (R12.8).
     *
     * Rounding and limiting deliberately do not happen here but centrally in
     * the {@see HandicapModule}.
     */
    public function apply(int $targetScore, float $average): float
    {
        return ((float) $targetScore - $average) * $this->factor;
    }

    /** Returns the configured factor. */
    public function factor(): float
    {
        return $this->factor;
    }
}
