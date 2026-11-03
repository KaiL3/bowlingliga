<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * A single net input field of the virtual score sheet (R30.5, R30.8–13).
 *
 * A field belongs to a team, a position (1–4) and a game (1–3). It carries
 * the net value, possibly prefilled (empty, from a draft or from OCR –
 * R30.6/R30.8/R30.17), the blind flag (R30.10), an optional derived blind
 * reference value (for display only, R30.10) and the needs-review flag
 * (R30.9/R30.11).
 *
 * For blind fields the stored net value always remains the sheet value; the
 * reference value only serves display (R30.10, R27.10). Pure, immutable value
 * object; the view is identical for manual and OCR entry (R30.7).
 */
final class ScoreSheetField
{
    /**
     * @param int      $gameNumber     Game 1–3
     * @param int|null $net            prefilled net value (0–300) or null
     * @param bool     $isBlind        Blind flag of the game (R30.10)
     * @param int|null $blindReference derived blind reference value for display
     *        (R30.10) or null (no reference value, e.g. first evening special case)
     * @param bool     $needsReview    to be highlighted visually (R30.9/R30.11)
     */
    public function __construct(
        public readonly int $gameNumber,
        public readonly ?int $net,
        public readonly bool $isBlind,
        public readonly ?int $blindReference,
        public readonly bool $needsReview,
    ) {
    }

    /**
     * Empty field for manual entry (R30.6).
     */
    public static function blank(int $gameNumber): self
    {
        return new self($gameNumber, null, false, null, false);
    }
}
