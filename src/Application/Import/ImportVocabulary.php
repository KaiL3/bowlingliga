<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Import;

use LogicException;

/**
 * The words the player import understands: the column names per field and
 * the values of the gender column, in all languages at once (R6).
 *
 * Built from the language catalogs ({@see \Bowlingliga\I18n\CatalogImportVocabulary}),
 * so a new language brings its column names along. Comparison ignores case,
 * surrounding spaces and repeated spaces. A word that would mean two
 * different things is rejected when building the vocabulary.
 */
final class ImportVocabulary
{
    /** Fields of the file; the first three are required. */
    public const FIELDS = ['team', 'name', 'gender', 'previous_average', 'previous_games'];

    public const REQUIRED = ['team', 'name', 'gender'];

    /** @var array<string, string> normalised column name → field */
    private array $columns = [];

    /** @var array<string, 'male'|'female'> normalised value → gender */
    private array $genders = [];

    /**
     * @param array<string, list<string>>            $columns field → column names
     * @param array{male: list<string>, female: list<string>} $genders gender → accepted values
     */
    public function __construct(array $columns, array $genders)
    {
        foreach ($columns as $field => $names) {
            if (! in_array($field, self::FIELDS, true)) {
                throw new LogicException("Unknown import field {$field}.");
            }
            foreach ($names as $name) {
                $this->add($this->columns, $name, $field, 'column name');
            }
        }
        foreach (self::REQUIRED as $field) {
            if (! in_array($field, $this->columns, true)) {
                throw new LogicException("No column name for the import field {$field}.");
            }
        }
        foreach ($genders as $gender => $values) {
            foreach ($values as $value) {
                $this->add($this->genders, $value, $gender, 'gender value');
            }
        }
    }

    /** The field of a column name, or null for an unknown column. */
    public function field(string $columnName): ?string
    {
        return $this->columns[self::normalise($columnName)] ?? null;
    }

    /** @return 'male'|'female'|null the gender of a cell value, null if unknown */
    public function gender(string $value): ?string
    {
        return $this->genders[self::normalise($value)] ?? null;
    }

    /**
     * @param array<string, string> $map
     */
    private function add(array &$map, string $word, string $meaning, string $kind): void
    {
        $key = self::normalise($word);
        if ($key === '') {
            return;
        }
        if (isset($map[$key]) && $map[$key] !== $meaning) {
            throw new LogicException("The import {$kind} \"{$word}\" means both {$map[$key]} and {$meaning}.");
        }
        $map[$key] = $meaning;
    }

    private static function normalise(string $word): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($word)));
    }
}
