<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\Import\PlayerImportPlan;
use Bowlingliga\Application\Import\PlayerImportRow;
use Bowlingliga\Domain\Message;
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
            <pre class="import-example">Team;Name;Geschlecht;Vorjahr Schnitt;Vorjahr Spiele
            Pin Busters;Kurt;m;143,64;99
            Jolly Rollers;Alma;w;;</pre>
            <ul>
                <li>{$this->text('import.help_gender', [], ['column' => '<strong>Geschlecht</strong>'])}</li>
                <li>{$this->text('import.help_previous', [], ['average' => '<strong>Vorjahr Schnitt</strong>', 'games' => '<strong>Vorjahr Spiele</strong>'])}</li>
                <li>{$this->text('import.help_names')}</li>
                <li>{$this->text('import.help_teams')}</li>
            </ul>
            HTML);
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
