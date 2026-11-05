<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Import;

use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\PreviousSeasonContext;
use Bowlingliga\Application\PreviousSeasonRecordManagement;
use Bowlingliga\Application\PreviousSeasonRecordManagementException;
use Bowlingliga\Application\TeamManagement;
use Bowlingliga\Application\Transaction;
use Bowlingliga\Domain\Message;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\WriteAccess;

/**
 * Writing use case: imports players with their teams into a season from a
 * CSV file (see {@see PlayerCsvParser} for the format).
 *
 * First the whole file is checked ({@see self::plan()}); it is only imported
 * if there is no error at all. Rules:
 * - Every player name may appear only once in the file and must not exist
 *   yet – the same name leads to an abort, since it cannot be told whether it
 *   is the same person.
 * - Teams of the season with the same name are reused, others are created.
 *   The ghost team cannot be imported into.
 * - The previous season (average and games) is stored as previous season
 *   record of the season; this is only possible before the season starts
 *   (R29.9).
 *
 * The import runs the existing use cases for team, player, roster and
 * previous season record in one transaction, so their checks apply and an
 * error leaves the database unchanged.
 */
final class PlayerImport
{
    /** Upper bound of lines, against accidentally uploaded large files. */
    public const MAX_ROWS = 1000;

    public function __construct(
        private readonly PlayerCsvParser $parser,
        private readonly PlayerImportReader $reader,
        private readonly PreviousSeasonContext $previousSeason,
        private readonly PlayerManagement $players,
        private readonly TeamManagement $teams,
        private readonly PreviousSeasonRecordManagement $records,
        private readonly Transaction $transaction,
        private readonly Validator $validator,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * Checks the file for a season without saving anything.
     */
    public function plan(int $seasonId, string $content): PlayerImportPlan
    {
        if (! $this->reader->seasonExists($seasonId)) {
            return new PlayerImportPlan([], [new Message('import.error.season_missing')], [], []);
        }

        ['rows' => $rows, 'errors' => $errors] = $this->parser->parse($content);
        if ($rows === [] && $errors === []) {
            $errors[] = new Message('import.error.no_rows');
        }
        if (count($rows) > self::MAX_ROWS) {
            return new PlayerImportPlan([], [new Message('import.error.too_many_rows', ['max' => self::MAX_ROWS])], [], []);
        }

        $valid = [];
        foreach ($rows as $row) {
            $problem = $this->rowProblem($row);
            if ($problem !== null) {
                $errors[] = new Message('import.error.line', ['line' => $row->line, 'problem' => $problem]);
            } else {
                $valid[] = $row;
            }
        }

        $errors = [...$errors, ...$this->nameConflicts($rows)];

        $hasRecords = array_filter($rows, static fn (PlayerImportRow $row): bool => $row->hasPreviousSeason()) !== [];
        if ($hasRecords) {
            $blocked = $this->previousSeasonBlocked($seasonId);
            if ($blocked !== null) {
                $errors[] = new Message('import.error.previous_blocked', ['reason' => $blocked] + PlayerCsvParser::previousColumns());
            }
        }

        [$newTeams, $existingTeams, $teamErrors] = $this->teamsOf($seasonId, $valid);

        return new PlayerImportPlan($valid, [...$errors, ...$teamErrors], $newTeams, $existingTeams);
    }

    /**
     * Imports an error-free file in one transaction.
     *
     * @return array{players: int, new_teams: int, records: int}
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws PlayerImportException if the file has errors (nothing saved).
     */
    public function import(int $seasonId, string $content): array
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }

        $plan = $this->plan($seasonId, $content);
        if (! $plan->isValid()) {
            throw PlayerImportException::invalidFile($plan->errors);
        }

        return $this->transaction->perform(function () use ($seasonId, $plan): array {
            $teamIds = [];
            foreach ($this->reader->seasonTeams($seasonId) as $team) {
                $teamIds[self::normalise($team['name'])] = $team['id'];
            }
            foreach ($plan->newTeams as $team) {
                $teamIds[self::normalise($team)] = $this->teams->createTeam($seasonId, $team);
            }

            foreach ($plan->rows as $row) {
                $playerId = $this->players->createPlayer($row->name, $row->gender);
                $this->players->assignPlayerToTeamSeason($teamIds[self::normalise($row->team)], $playerId);
                if ($row->hasPreviousSeason()) {
                    $this->records->enterRecord($seasonId, $playerId, (int) $row->previousGames, $row->previousPins());
                }
            }

            return [
                'players'   => count($plan->rows),
                'new_teams' => count($plan->newTeams),
                'records'   => $plan->rowsWithPreviousSeason(),
            ];
        });
    }

    /**
     * Comparison form of names: case and repeated spaces are ignored.
     */
    public static function normalise(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    private function rowProblem(PlayerImportRow $row): ?Message
    {
        try {
            $this->validator->checkName($row->team, 'field.team_name');
            $this->validator->checkName($row->name);
            if ($row->hasPreviousSeason()) {
                $this->validator->checkPreviousSeasonRecord((int) $row->previousGames, $row->previousPins());
            }
        } catch (ValidationException $e) {
            return $e->message();
        }

        return null;
    }

    /**
     * Same names within the file or names of existing players.
     *
     * @param list<PlayerImportRow> $rows
     *
     * @return list<Message>
     */
    private function nameConflicts(array $rows): array
    {
        $errors = [];
        $lines = [];
        foreach ($rows as $row) {
            $lines[self::normalise($row->name)][] = $row;
        }
        $existing = array_flip(array_map(self::normalise(...), $this->reader->playerNames()));

        foreach ($lines as $key => $sameName) {
            $numbers = implode(', ', array_map(static fn (PlayerImportRow $row): string => (string) $row->line, $sameName));
            if (count($sameName) > 1) {
                $errors[] = new Message('import.error.name_twice', ['lines' => $numbers, 'name' => $sameName[0]->name]);
            }
            if (isset($existing[$key])) {
                $errors[] = new Message('import.error.name_exists', ['lines' => $numbers, 'name' => $sameName[0]->name]);
            }
        }

        return $errors;
    }

    private function previousSeasonBlocked(int $seasonId): ?Message
    {
        if ($this->previousSeason->isSeasonStarted($seasonId)) {
            return PreviousSeasonRecordManagementException::seasonAlreadyStarted()->message();
        }

        return null;
    }

    /**
     * @param list<PlayerImportRow> $rows
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<Message>} new teams, existing teams, errors
     */
    private function teamsOf(int $seasonId, array $rows): array
    {
        $existing = [];
        $ghosts = [];
        foreach ($this->reader->seasonTeams($seasonId) as $team) {
            if ($team['is_ghost_team']) {
                $ghosts[self::normalise($team['name'])] = true;
            } else {
                $existing[self::normalise($team['name'])] = $team['name'];
            }
        }

        $new = [];
        $used = [];
        $errors = [];
        foreach ($rows as $row) {
            $key = self::normalise($row->team);
            if (isset($ghosts[$key])) {
                $errors[] = new Message('import.error.ghost_team', ['line' => $row->line, 'team' => $row->team]);
            } elseif (isset($existing[$key])) {
                $used[$key] = $existing[$key];
            } else {
                $new[$key] ??= $row->team;
            }
        }

        return [array_values($new), array_values($used), $errors];
    }
}
