<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Import;

use Bowlingliga\Domain\Message;

/**
 * Checked import file, shown as a preview before anything is saved.
 *
 * Only an error-free plan can be imported.
 */
final class PlayerImportPlan
{
    /**
     * @param list<PlayerImportRow> $rows          Valid lines.
     * @param list<Message>         $errors        Problems with line numbers.
     * @param list<string>          $newTeams      Teams that will be created.
     * @param list<string>          $existingTeams Teams of the season the players join.
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
        public readonly array $newTeams,
        public readonly array $existingTeams,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->rows !== [];
    }

    /** Whether the team of a row is created by the import. */
    public function isNewTeam(string $team): bool
    {
        return in_array(PlayerImport::normalise($team), array_map(PlayerImport::normalise(...), $this->newTeams), true);
    }

    public function rowsWithPreviousSeason(): int
    {
        return count(array_filter($this->rows, static fn (PlayerImportRow $row): bool => $row->hasPreviousSeason()));
    }
}
