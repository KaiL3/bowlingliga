<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Positionday;

use Bowlingliga\Domain\Message;

/**
 * Result of the positionday wiring (R31.2, R31.4, R31.5).
 *
 * Encapsulates the two domain outcomes of the use case
 * {@see PositiondayManagement::formAndSave()}:
 *
 * - Formed: all previous matchdays were "complete"; the fixtures were formed
 *   from the standings, given a lane assignment and saved (R31.2, R31.5).
 *   {@see formed} is true; a possible lane warning (R18.9) is in
 *   {@see warnings}.
 * - Not ready: at least one previous matchday is not "complete" yet; nothing
 *   was saved, and {@see note} names the reason (R31.4).
 *
 * Pure, immutable value object.
 */
final class PositiondayFormationResult
{
    /**
     * @param bool         $formed   true if the fixtures were formed and saved
     *                               (R31.2)
     * @param Message|null $note     Note that previous matchdays are not complete
     *                               yet (R31.4); null on success
     * @param list<Message> $warnings possible lane warning (R18.9); otherwise empty
     */
    private function __construct(
        public readonly bool $formed,
        public readonly ?Message $note,
        public readonly array $warnings,
    ) {
    }

    /**
     * @param list<Message> $warnings
     */
    public static function formed(array $warnings = []): self
    {
        return new self(true, null, $warnings);
    }

    public static function notReady(Message $note): self
    {
        return new self(false, $note, []);
    }
}
