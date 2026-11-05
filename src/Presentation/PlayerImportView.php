<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\Import\ImportVocabulary;
use Bowlingliga\Application\Import\PlayerImportPlan;
use Bowlingliga\Application\Import\PlayerImportRow;
use Bowlingliga\Domain\Message;
use Bowlingliga\I18n\CatalogImportVocabulary;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * Views of the player import: upload form with format description and the
 * preview of a checked file. Every output is escaped.
 */
final class PlayerImportView
{
    use ViewTexts;

    private readonly Formatter $format;

    private readonly ValueLabels $labels;

    public function __construct(private readonly Translator $t = new Translator())
    {
        $this->format = new Formatter($t);
        $this->labels = new ValueLabels($t);
    }

    /**
     * @param list<array<string, mixed>> $seasons
     */
    public function renderForm(string $csrfField, array $seasons, ?int $seasonId, ?string $error = null): string
    {
        if ($seasons === []) {
            return AdminComponents::card($this->plain('import.title'), '<p class="empty">' . $this->text('import.season_first') . '</p>');
        }

        $options = '';
        foreach ($seasons as $season) {
            $options .= sprintf(
                '<option value="%d"%s>%s</option>',
                (int) $season['id'],
                (int) $season['id'] === $seasonId ? ' selected' : '',
                $this->e((string) $season['name']),
            );
        }
        $note = $error !== null ? AdminComponents::notes(null, $error) : '';
        $columns = implode(', ', array_map($this->column(...), ImportVocabulary::FIELDS));

        return $note . AdminComponents::card($this->plain('import.title'), <<<HTML
            <form class="form" method="post" action="/admin/master-data/import-preview" enctype="multipart/form-data">
                {$csrfField}
                <div class="form__field">
                    <label for="import-season">{$this->text('layout.season')}</label>
                    <select id="import-season" name="season_id">{$options}</select>
                </div>
                <div class="form__field">
                    <label for="import-file">{$this->text('import.file')}</label>
                    <input type="file" id="import-file" name="file" accept=".csv,text/csv" required>
                </div>
                <button type="submit" class="button">{$this->text('import.show_preview')}</button>
            </form>
            <h3 class="import-help">{$this->text('import.format_title')}</h3>
            <p>{$this->text('import.format_text')}</p>
            <p><a class="button button--secondary" href="/admin/master-data/import-template" download>{$this->text('import.template')}</a></p>
            <pre class="import-example">{$this->e($this->example())}</pre>
            <ul>
                <li>{$this->text('import.help_columns', [], ['columns' => $columns])}</li>
                <li>{$this->text('import.help_gender', ['male' => CatalogImportVocabulary::genderValue($this->t, 'male'), 'female' => CatalogImportVocabulary::genderValue($this->t, 'female')], ['column' => $this->column('gender')])}</li>
                <li>{$this->text('import.help_previous', [], ['average' => $this->column('previous_average'), 'games' => $this->column('previous_games')])}</li>
                <li>{$this->text('import.help_names')}</li>
                <li>{$this->text('import.help_teams')}</li>
            </ul>
            HTML);
    }

    /**
     * The template file in the visitor's language: UTF-8 with BOM (so that
     * Excel shows umlauts), the column names and two example lines (R6).
     */
    public function template(): string
    {
        return "\xEF\xBB\xBF" . $this->example();
    }

    /** File name of the template in the visitor's language. */
    public function templateFileName(): string
    {
        return $this->plain('import.template_file');
    }

    /**
     * Column names and two example lines in the visitor's language, with its
     * separator and decimal mark.
     */
    private function example(): string
    {
        $separator = $this->t->t('import.template_separator');
        $lines = [
            array_map(fn (string $field): string => CatalogImportVocabulary::columnName($this->t, $field), ImportVocabulary::FIELDS),
            ['Pin Busters', 'Kurt', CatalogImportVocabulary::genderValue($this->t, 'male'), $this->format->number(143.64, 2), '99'],
            ['Jolly Rollers', 'Alma', CatalogImportVocabulary::genderValue($this->t, 'female'), '', ''],
        ];

        // Quotes only where needed, so that the example stays readable.
        $cell = static fn (string $value): string => strpbrk($value, $separator . "\"\r\n") === false
            ? $value
            : '"' . str_replace('"', '""', $value) . '"';

        return implode('', array_map(static fn (array $line): string => implode($separator, array_map($cell, $line)) . "\r\n", $lines));
    }

    /** A column name in the visitor's language, highlighted. */
    private function column(string $field): string
    {
        return '<strong>' . $this->e(CatalogImportVocabulary::columnName($this->t, $field)) . '</strong>';
    }

    public function renderPreview(string $csrfField, PlayerImportPlan $plan, string $seasonName, int $seasonId, string $encodedContent): string
    {
        $rows = '';
        foreach ($plan->rows as $row) {
            $rows .= $this->row($row, $plan->isNewTeam($row->team));
        }
        $table = $rows === '' ? '' : <<<HTML
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th scope="col">{$this->text('import.line')}</th><th scope="col">{$this->text('common.team')}</th><th scope="col">{$this->text('field.name')}</th><th scope="col">{$this->text('field.gender')}</th><th scope="col">{$this->text('import.previous_average')}</th><th scope="col">{$this->text('import.previous_games')}</th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
            HTML;

        $summary = '<p>' . $this->text('import.summary', [
            'players'  => count($plan->rows),
            'season'   => $seasonName !== '' ? $seasonName : $this->plain('import.the_season'),
            'new'      => count($plan->newTeams),
            'existing' => count($plan->existingTeams),
            'records'  => $plan->rowsWithPreviousSeason(),
        ]) . '</p>';

        if (! $plan->isValid()) {
            $errors = implode('', array_map(fn (Message $error): string => '<li>' . $this->messageText($error) . '</li>', $plan->errors));

            return AdminComponents::card(
                $this->plain('import.preview'),
                '<div class="error" role="alert"><p>' . $this->text('import.has_errors') . '</p><ul>' . $errors . '</ul></div>'
                . $summary . $table,
            );
        }

        $content = $this->e($encodedContent);

        return AdminComponents::card($this->plain('import.preview'), $summary . $table . <<<HTML
            <form class="form" method="post" action="/admin/master-data/import">
                {$csrfField}
                <input type="hidden" name="season_id" value="{$seasonId}">
                <input type="hidden" name="csv" value="{$content}">
                <button type="submit" class="button">{$this->text('import.import')}</button>
            </form>
            HTML);
    }

    private function row(PlayerImportRow $row, bool $newTeam): string
    {
        return sprintf(
            '<tr><td>%d</td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            $row->line,
            $this->e($row->team),
            $newTeam ? ' <span class="badge badge--neutral">' . $this->text('import.new') . '</span>' : '',
            $this->e($row->name),
            $this->e($this->labels->gender($row->gender)),
            $row->previousAverage !== null ? $this->e($this->format->number($row->previousAverage, 2)) : '',
            $row->previousGames !== null ? (string) $row->previousGames : '',
        );
    }

    private function e(string $value): string
    {
        return AdminComponents::e($value);
    }
}
