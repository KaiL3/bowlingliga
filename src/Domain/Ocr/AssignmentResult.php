<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Ocr;

/**
 * Result of assigning recognized game data to a matchday and a fixture
 * (R3.3–R3.5).
 *
 * The assignment has exactly one of three outcomes:
 * - {@see self::unique()}: exactly one fixture matches date and team names
 *   (R3.3).
 * - {@see self::ambiguous()}: several fixtures exist on the recognized date
 *   and the team names do not allow a unique assignment; the selection is to
 *   be presented to the administrator (R3.4).
 * - {@see self::noMatchday()}: no matchday exists for the recognized date;
 *   the assignment is suspended and an error message is shown (R3.5).
 *
 * Pure, immutable value object.
 */
final class AssignmentResult
{
    public const UNIQUE = 'eindeutig';
    public const AMBIGUOUS = 'mehrdeutig';
    public const NO_MATCHDAY = 'kein_spieltag';

    /**
     * @param self::*     $status              Outcome of the assignment
     * @param int|null    $matchdayId          assigned matchday (for unique/ambiguous)
     * @param int|null    $fixtureId           assigned fixture (only for unique)
     * @param list<int>   $candidateFixtureIds Selection on ambiguity (R3.4)
     * @param string|null $message             Error/note text (R3.5)
     */
    private function __construct(
        public readonly string $status,
        public readonly ?int $matchdayId,
        public readonly ?int $fixtureId,
        public readonly array $candidateFixtureIds,
        public readonly ?string $message,
    ) {
    }

    /**
     * Unique assignment to matchday and fixture (R3.3).
     */
    public static function unique(int $matchdayId, int $fixtureId): self
    {
        return new self(self::UNIQUE, $matchdayId, $fixtureId, [], null);
    }

    /**
     * Ambiguous assignment: the administrator chooses from the candidates (R3.4).
     *
     * @param list<int> $candidateFixtureIds
     */
    public static function ambiguous(int $matchdayId, array $candidateFixtureIds): self
    {
        return new self(
            self::AMBIGUOUS,
            $matchdayId,
            null,
            $candidateFixtureIds,
            'Die erkannten Teamnamen konnten keiner Teampaarung eindeutig zugeordnet werden. Bitte die zutreffende Teampaarung auswählen.'
        );
    }

    /**
     * No matchday exists for the recognized date (R3.5).
     */
    public static function noMatchday(): self
    {
        return new self(
            self::NO_MATCHDAY,
            null,
            null,
            [],
            'Zum erkannten Datum existiert kein Spieltag. Die Zuordnung wird ausgesetzt.'
        );
    }

    public function isUnique(): bool
    {
        return $this->status === self::UNIQUE;
    }
}
