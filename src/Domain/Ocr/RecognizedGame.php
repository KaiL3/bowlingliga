<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Ocr;

/**
 * A game result recognized by the OCR module (R3.7–R3.11).
 *
 * Describes for one position and one game the recognized net value, the
 * recognized blind marking ("X" to the right of the net average, R3.7) and
 * whether the value needs review (ambiguous recognition, R3.9, or deviation of
 * the blind sheet value from the reference value, R3.11).
 *
 * For games marked blind the recognized net value is the authoritative sheet
 * value and is not replaced by a derived blind value (R3.10, R27.10).
 *
 * Pure, immutable value object.
 */
final class RecognizedGame
{
    /**
     * @param int      $position    Lineup position 1–4
     * @param int      $gameNumber  Game 1–3
     * @param int|null $net         recognized net value (0–300) or null for an
     *                              unreadable value
     * @param bool     $isBlind     true if a blind marker "X" was recognized
     *                              (R3.7)
     * @param bool     $needsReview true if the value is to be presented to the
     *                              administrator for review (R3.9, R3.11)
     */
    public function __construct(
        public readonly int $position,
        public readonly int $gameNumber,
        public readonly ?int $net,
        public readonly bool $isBlind,
        public readonly bool $needsReview,
    ) {
    }
}
