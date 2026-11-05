<?php

declare(strict_types=1);

namespace Bowlingliga\I18n;

use Bowlingliga\Application\Import\ImportVocabulary;

/**
 * Builds the {@see ImportVocabulary} from all language catalogs: the player
 * import accepts the column names and gender values of every language, also
 * mixed in one file (R6).
 *
 * Catalog keys per field (`team`, `name`, `gender`, `previous_average`,
 * `previous_games`): `import.column.<field>` is the column name shown to the
 * visitor, `import.column.<field>.aliases` holds further accepted names
 * separated by `|` (may be empty). `import.gender.male` and
 * `import.gender.female` list the accepted values, the first one is shown.
 */
final class CatalogImportVocabulary
{
    public static function all(): ImportVocabulary
    {
        $columns = array_fill_keys(ImportVocabulary::FIELDS, []);
        $genders = ['male' => [], 'female' => []];

        foreach (Languages::codes() as $language) {
            $catalog = Translator::catalog($language);
            foreach (ImportVocabulary::FIELDS as $field) {
                $columns[$field] = [
                    ...$columns[$field],
                    $catalog["import.column.{$field}"] ?? '',
                    ...self::words($catalog["import.column.{$field}.aliases"] ?? ''),
                ];
            }
            foreach (array_keys($genders) as $gender) {
                $genders[$gender] = [...$genders[$gender], ...self::words($catalog["import.gender.{$gender}"] ?? '')];
            }
        }

        return new ImportVocabulary($columns, $genders);
    }

    /** The column name of a field in the visitor's language. */
    public static function columnName(Translator $t, string $field): string
    {
        return $t->t("import.column.{$field}");
    }

    /** The gender value shown in the visitor's language (the first accepted one). */
    public static function genderValue(Translator $t, string $gender): string
    {
        return self::words($t->t("import.gender.{$gender}"))[0] ?? $gender;
    }

    /**
     * @return list<string>
     */
    private static function words(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $list)), static fn (string $word): bool => $word !== ''));
    }
}
