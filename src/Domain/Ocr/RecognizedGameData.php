<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Ocr;

/**
 * The game data of a score sheet recognized by the OCR module and not yet
 * stored (R3.1, R3.3, R3.6, R3.9).
 *
 * Bundles the recognized date, the recognized team names and the recognized
 * game results. This structure is presented to the administrator for review
 * and correction (R3.6, R3.12) and only stored after explicit confirmation
 * (R3.13).
 *
 * Pure, immutable value object without side effects.
 */
final class RecognizedGameData
{
    /**
     * @param string|null          $date            recognized date (Y-m-d) or null
     * @param list<string>         $teamNames       recognized team names (0–2)
     * @param list<RecognizedGame> $games           recognized games
     * @param bool                 $dateNeedsReview true for an uncertain date (R3.9)
     */
    public function __construct(
        public readonly ?string $date,
        public readonly array $teamNames,
        public readonly array $games,
        public readonly bool $dateNeedsReview = false,
    ) {
    }

    /**
     * Tells whether any recognized value needs review and is to be presented to
     * the administrator for rework (R3.9, R3.11).
     */
    public function hasValuesNeedingReview(): bool
    {
        if ($this->dateNeedsReview) {
            return true;
        }

        foreach ($this->games as $game) {
            if ($game->needsReview) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges the games of this data with those of another recognized record
     * (R3.18, merging split score sheets).
     *
     * Date and team names are taken from this record if present, otherwise
     * from the other one. The games of both halves are combined; for the same
     * (position, game) this record takes precedence.
     */
    public function mergeWith(self $other): self
    {
        $games = [];
        foreach ([...$other->games, ...$this->games] as $game) {
            $key = $game->position . '-' . $game->gameNumber;
            $games[$key] = $game;
        }

        return new self(
            $this->date ?? $other->date,
            $this->teamNames !== [] ? $this->teamNames : $other->teamNames,
            array_values($games),
            $this->dateNeedsReview || $other->dateNeedsReview,
        );
    }
}
