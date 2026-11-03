<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

use Bowlingliga\Domain\Message;

/**
 * Result of a handicap calculation (pure, without side effects).
 *
 * Carries either a calculated integer handicap or one of the error/special
 * cases defined in R12 together with an error message. Based on
 * {@see self::hasHandicap()} the calling layer decides whether a gross can be
 * determined.
 *
 * @see R12.13, R12.14, R12.16
 */
final class HandicapResult
{
    /**
     * @param HandicapStatus $status   Result status.
     * @param int|null       $handicap Integer handicap or null in the error case.
     * @param Message|null   $message  Error message if the case requires one.
     */
    private function __construct(
        private readonly HandicapStatus $status,
        private readonly ?int $handicap,
        private readonly ?Message $message,
    ) {
    }

    /**
     * Erfolgreich berechnetes Handicap (R12.8/R12.10/R12.15).
     */
    public static function calculated(int $handicap): self
    {
        return new self(HandicapStatus::CALCULATED, $handicap, null);
    }

    /**
     * Missing handicap formula: no handicap/gross + error message (R12.13).
     */
    public static function missingFormula(): self
    {
        return new self(
            HandicapStatus::MISSING_FORMULA,
            null,
            new Message('handicap.missing_formula'),
        );
    }

    /**
     * No average available (except first matchday): "without handicap" (R12.14).
     */
    public static function withoutHandicap(): self
    {
        return new self(HandicapStatus::WITHOUT_HANDICAP, null, null);
    }

    /**
     * Invalid formula result: "without handicap" + error message (R12.16).
     */
    public static function invalidFormulaResult(): self
    {
        return new self(
            HandicapStatus::INVALID_FORMULA_RESULT,
            null,
            new Message('handicap.invalid_result'),
        );
    }

    /** Ergebnisstatus. */
    public function status(): HandicapStatus
    {
        return $this->status;
    }

    /**
     * Integer handicap or null if there is no handicap.
     */
    public function handicap(): ?int
    {
        return $this->handicap;
    }

    /** Error message if the case carries one, otherwise null. */
    public function message(): ?Message
    {
        return $this->message;
    }

    /** Tells whether a calculated handicap exists. */
    public function hasHandicap(): bool
    {
        return $this->status === HandicapStatus::CALCULATED && $this->handicap !== null;
    }
}
