<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\ResultEntry\FixtureLogEntry;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * Renders the history of a fixture as a collapsible card on the virtual score
 * sheet (admin area only): time, who (team via code or admin account), what,
 * player, game and value before → after. Every value is escaped.
 */
final class FixtureHistoryView
{
    use ViewTexts;

    /** Actions with a label (`history.action.<action>`); the confirmation differs between team and admin. */
    private const ACTIONS = [
        'lineup', 'net', 'nets', 'submitted', 'withdrawn', 'reopened', 'rejected', 'final',
        'taken_over', 'codes_renewed', 'draft_saved', 'corrected', 'removed',
    ];

    private readonly Formatter $format;

    public function __construct(private readonly Translator $t = new Translator())
    {
        $this->format = new Formatter($t);
    }

    /**
     * @param list<FixtureLogEntry>                 $entries newest first
     * @param array{home: string, away: string} $teams   team names per side
     */
    public function render(array $entries, array $teams): string
    {
        if ($entries === []) {
            return AdminComponents::card($this->plain('history.title'), '<p class="empty">' . $this->text('history.empty') . '</p>');
        }

        $rows = '';
        foreach ($entries as $entry) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->e($this->time($entry->createdAt)),
                $this->e($this->who($entry, $teams)),
                $this->e($this->what($entry)),
                $this->e($entry->playerId !== null ? ($entry->playerName ?? $this->plain('common.player_number', ['number' => $entry->playerId])) : ''),
                $entry->gameNumber !== null ? $this->text('history.game_short', ['number' => $entry->gameNumber]) : '',
                $this->e($this->value($entry)),
            );
        }

        $table = '<div class="table-scroll"><table class="table history">'
            . '<thead><tr><th scope="col">' . $this->text('history.time') . '</th><th scope="col">' . $this->text('history.who') . '</th><th scope="col">' . $this->text('history.what') . '</th>'
            . '<th scope="col">' . $this->text('common.player') . '</th><th scope="col">' . $this->text('team_entry.game') . '</th><th scope="col">' . $this->text('history.value') . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';

        return AdminComponents::card(
            $this->plain('history.title'),
            '<details class="collapsible"><summary class="collapsible__header"><span class="collapsible__title">'
            . $this->text('history.show_all') . '</span></summary>' . $table . '</details>',
            $this->plain('history.count', ['count' => count($entries)]),
        );
    }

    private function time(string $createdAt): string
    {
        $time = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAt);

        return $time !== false ? $this->format->date($time->format('Y-m-d')) . ' ' . $time->format('H:i:s') : $createdAt;
    }

    /** @param array{home: string, away: string} $teams */
    private function who(FixtureLogEntry $entry, array $teams): string
    {
        return match ($entry->side) {
            'home'  => $this->plain('history.home', ['team' => $teams['home']]),
            'away'  => $this->plain('history.away', ['team' => $teams['away']]),
            default => $entry->actor !== null ? $this->plain('history.admin_named', ['name' => $entry->actor]) : $this->plain('layout.admin'),
        };
    }

    private function what(FixtureLogEntry $entry): string
    {
        if ($entry->action === 'confirmed') {
            $label = $this->plain($entry->side !== null ? 'history.action.confirmed_team' : 'history.action.confirmed_admin');
        } else {
            $label = in_array($entry->action, self::ACTIONS, true) ? $this->plain('history.action.' . $entry->action) : $entry->action;
        }

        $detail = $this->detail($entry);

        return $detail !== '' ? $label . ': ' . $detail : $label;
    }

    /**
     * Detail of an entry: known codes in the visitor's language, any other
     * text (a rejection note, older entries) as stored.
     */
    private function detail(FixtureLogEntry $entry): string
    {
        return match (true) {
            $entry->detail === FixtureLogEntry::FINAL_GHOST_TEAM     => $this->plain('history.final_ghost_team'),
            $entry->detail === FixtureLogEntry::FINAL_BOTH_CONFIRMED => $this->plain('history.final_both_confirmed'),
            $entry->action === 'taken_over' && ctype_digit($entry->detail) => $this->plain('history.taken_over_count', ['count' => (int) $entry->detail]),
            default => $entry->detail,
        };
    }

    private function value(FixtureLogEntry $entry): string
    {
        if ($entry->before === null && $entry->after === null) {
            return '';
        }

        return ($entry->before ?? '–') . ' → ' . ($entry->after ?? '–');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
