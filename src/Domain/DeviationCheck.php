<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * Result of the plausibility check of a game marked blind against its derived
 * reference value (R27.11, R3.11, R22.2).
 *
 * Represents the three distinguishable domain states explicitly so that the
 * calling result entry (R30) can evaluate them without resorting to special
 * values:
 *
 * - No reference value (first evening special case, R27.16): neither a
 *   deviation nor a review request; the sheet value is taken without
 *   additional confirmation.
 * - Reference value present and sheet value == reference value: no deviation,
 *   no review needed, can be saved without additional confirmation (R27.11).
 * - Reference value present and sheet value != reference value: deviation,
 *   needs review; requires correction or explicit approval of the sheet value
 *   before saving (R27.11).
 *
 * The stored net value remains the sheet value in all cases (R27.10); this
 * object does not change it.
 */
final class DeviationCheck
{
    private function __construct(
        public readonly int $sheetValue,
        public readonly ?int $referenceValue,
        public readonly bool $referenceValueExists,
        public readonly bool $needsReview
    ) {
    }

    /**
     * First evening special case without reference value (R27.16): the
     * substitute player has neither a net average nor a handicap, so no
     * reference is calculated and no deviation is flagged.
     */
    public static function withoutReference(int $sheetValue): self
    {
        return new self($sheetValue, null, false, false);
    }

    /**
     * Regular case with a reference value (R27.11): needs review exactly when
     * the sheet value differs from the reference value.
     */
    public static function withReference(int $sheetValue, int $referenceValue): self
    {
        return new self(
            $sheetValue,
            $referenceValue,
            true,
            $sheetValue !== $referenceValue
        );
    }

    /**
     * The authoritative stored net value is always the sheet value (R27.10,
     * R3.10, R22.2).
     */
    public function storedNet(): int
    {
        return $this->sheetValue;
    }
}
