<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Import;

use Bowlingliga\Domain\Message;

/**
 * Reads the player import file (CSV) without side effects.
 *
 * Expected columns (header in the first line, order free, case ignored):
 * `Team`, `Name`, `Geschlecht` (`m`/`w`) and optionally `Vorjahr Schnitt`
 * and `Vorjahr Spiele` – either both or neither filled in per line.
 *
 * Separator `;`, `,` or tab is detected from the header. The file may be
 * UTF-8 (with or without BOM) or Windows-1252, the default of Excel on
 * Windows; empty lines are skipped. Problems are collected per line instead
 * of stopping at the first one, so that the preview can list all of them.
 */
final class PlayerCsvParser
{
    /** @var array<string, string> normalised header → field */
    private const COLUMNS = [
        'team'            => 'team',
        'name'            => 'name',
        'geschlecht'      => 'gender',
        'vorjahr schnitt' => 'previous_average',
        'vorjahr spiele'  => 'previous_games',
    ];

    private const REQUIRED = ['team' => 'Team', 'name' => 'Name', 'gender' => 'Geschlecht'];

    /**
     * @return array{rows: list<PlayerImportRow>, errors: list<Message>}
     */
    public function parse(string $content): array
    {
        $content = self::toUtf8($content);
        $lines = preg_split('/\R/u', $content) ?: [];

        $headerIndex = null;
        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $headerIndex = $index;
                break;
            }
        }
        if ($headerIndex === null) {
            return ['rows' => [], 'errors' => [new Message('import.error.empty')]];
        }

        $delimiter = self::delimiter($lines[$headerIndex]);
        $columns = [];
        $errors = [];
        foreach (self::cells($lines[$headerIndex], $delimiter) as $position => $title) {
            $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($title)));
            if ($key === '') {
                continue;
            }
            if (! isset(self::COLUMNS[$key])) {
                $errors[] = new Message('import.error.unknown_column', ['column' => trim($title)]);
                continue;
            }
            $columns[self::COLUMNS[$key]] = $position;
        }
        foreach (self::REQUIRED as $field => $label) {
            if (! isset($columns[$field])) {
                $errors[] = new Message('import.error.missing_column', ['column' => $label]);
            }
        }
        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors];
        }

        $rows = [];
        foreach (array_slice($lines, $headerIndex + 1, null, true) as $index => $line) {
            $cells = self::cells($line, $delimiter);
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }
            $value = static fn (string $field): string => isset($columns[$field]) ? trim($cells[$columns[$field]] ?? '') : '';
            $lineNumber = $index + 1;

            $row = self::row($lineNumber, $value('team'), $value('name'), $value('gender'), $value('previous_average'), $value('previous_games'));
            if ($row instanceof Message) {
                $errors[] = new Message('import.error.line', ['line' => $lineNumber, 'problem' => $row]);
            } else {
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Builds a row or returns the reason why the line is invalid.
     */
    private static function row(int $line, string $team, string $name, string $gender, string $average, string $games): PlayerImportRow|Message
    {
        if ($team === '') {
            return new Message('import.error.team_missing');
        }
        if ($name === '') {
            return new Message('import.error.name_missing');
        }
        $genderValue = match (mb_strtolower($gender)) {
            'm'     => 'male',
            'w'     => 'female',
            default => null,
        };
        if ($genderValue === null) {
            return new Message('import.error.gender', ['value' => $gender]);
        }

        if ($average === '' && $games === '') {
            return new PlayerImportRow($line, $team, $name, $genderValue);
        }
        if ($average === '' || $games === '') {
            return new Message('import.error.previous_both');
        }
        if (preg_match('/^\d{1,3}(?:[.,]\d+)?$/', $average) !== 1 || (float) str_replace(',', '.', $average) > 300) {
            return new Message('import.error.previous_average', ['value' => $average]);
        }
        if (preg_match('/^\d+$/', $games) !== 1 || (int) $games < 1) {
            return new Message('import.error.previous_games', ['value' => $games]);
        }

        return new PlayerImportRow($line, $team, $name, $genderValue, (float) str_replace(',', '.', $average), (int) $games);
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line, string $delimiter): array
    {
        return array_map(static fn (?string $cell): string => (string) $cell, str_getcsv($line, $delimiter, '"', ''));
    }

    private static function delimiter(string $header): string
    {
        $counts = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), "\t" => substr_count($header, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
}
