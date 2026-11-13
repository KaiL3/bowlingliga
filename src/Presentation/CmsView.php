<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Handicap\HandicapTable;

/**
 * View rendering of the public, read-only CMS content (R33.7–R33.9, R34.2,
 * R34.6).
 *
 * Renders the home page posts alongside the season selection (R33.8) and the
 * rules page (R34.2). The content is already sanitized or escaped on saving
 * (R33.11, R34.8): the title of a home page post is already escaped plain
 * text, the text content and the rules are already whitelist-sanitized HTML.
 * The view therefore outputs these stored values unchanged and escapes
 * nothing again, to keep the allowed text markup.
 *
 * Free of side effects: returns HTML strings; delivering them is up to the
 * caller.
 */
final class CmsView
{
    public function __construct(private readonly \Bowlingliga\I18n\Translator $t = new \Bowlingliga\I18n\Translator())
    {
    }

    /**
     * Renders the home page posts (messages) alongside the season selection
     * (R33.7–R33.9).
     *
     * @param list<array{title: string, text: string}> $contents home page posts
     *        already sanitized on saving (title escaped, text whitelist-sanitized)
     */
    public function renderHomePagePosts(array $contents): string
    {
        // R33.9: without posts nothing is added (the season selection stays the
        // entry point of the home page, R33.8).
        if ($contents === []) {
            return '';
        }

        $sections = '';
        foreach ($contents as $content) {
            // The title is already escaped, the text already sanitized (R33.11) – so
            // output them unchanged to keep the allowed markup.
            $sections .= sprintf(
                '<article class="card post"><h2>%s</h2><div class="post__text prose">%s</div></article>',
                $content['title'],
                $content['text'],
            );
        }

        return '<section class="posts" aria-label="' . $this->e($this->t->t('public.posts')) . '">' . $sections . '</section>'
            . $this->lightboxFor($sections);
    }

    /**
     * The overlay that shows an image of the text large (R42.7), only if the
     * text has images.
     */
    private function lightboxFor(string $html): string
    {
        return str_contains($html, '<img') ? (new Lightbox($this->t))->dialog() : '';
    }

    /**
     * Renders the rules page (R34.2, R34.6).
     *
     * With a handicap table the page gets two tabs, the rules text and the
     * handicap per average of the given season. Without one (no season or no
     * readable handicap formula) only the rules text is shown.
     *
     * @param string|null $text already sanitized rules content, or null if none
     *        has been entered yet
     */
    public function renderRules(?string $text, ?HandicapTable $handicapTable = null, string $seasonName = ''): string
    {
        // R34.6: note if no rules are stored yet (empty
        // or not entered content).
        $rules = $text === null || trim($text) === ''
            ? '<section class="card"><p class="empty">' . $this->e($this->t->t('public.no_rules')) . '</p></section>'
            // The text is already whitelist-sanitized (R34.8) – output it unchanged.
            : '<section class="card"><div class="rules prose">' . $text . '</div></section>' . $this->lightboxFor($text);

        if ($handicapTable === null || $handicapTable->rows === []) {
            return '<h1>' . $this->e($this->t->t('nav.rules')) . '</h1>' . $rules;
        }

        $handicap = $this->renderHandicapTable($handicapTable, $seasonName);
        $title    = $this->e($this->t->t('nav.rules'));
        $rulesTab = $this->e($this->t->t('public.rules_tab'));
        $hdcTab   = $this->e($this->t->t('public.handicap_table'));

        // Same tab component as the matchday view: without JavaScript both
        // panels are visible one below the other.
        return <<<HTML
            <h1>{$title}</h1>
            <div class="tabs" data-tabs>
                <div class="tabs__bar" role="tablist" aria-label="{$title}">
                    <button type="button" class="tabs__tab tabs__tab--active" id="rules-tab-text" role="tab" aria-controls="rules-panel-text" aria-selected="true">{$rulesTab}</button>
                    <button type="button" class="tabs__tab" id="rules-tab-handicap" role="tab" aria-controls="rules-panel-handicap" aria-selected="false">{$hdcTab}</button>
                </div>
                <div class="tabs__panels">
                    <section class="tabs__panel" id="rules-panel-text" role="tabpanel" aria-labelledby="rules-tab-text">{$rules}</section>
                    <section class="tabs__panel" id="rules-panel-handicap" role="tabpanel" aria-labelledby="rules-tab-handicap">{$handicap}</section>
                </div>
            </div>
            <script src="{$this->e(Layout::versioned('/assets/js/tabs.js'))}" defer></script>
            HTML;
    }

    /**
     * Handicap per whole-pin average, split into three columns of equal
     * length so that the list stays short on the phone as well.
     */
    private function renderHandicapTable(HandicapTable $table, string $seasonName): string
    {
        $rows = $table->rows;
        $lastIndex = array_key_last($rows);
        $cells = [];
        foreach ($rows as $index => $row) {
            $average = (string) $row->average;
            if ($index === 0 && $table->isOpenBelow()) {
                $average = '≤ ' . $average;
            } elseif ($index === $lastIndex && $table->isOpenAbove()) {
                $average = '≥ ' . $average;
            }
            $cells[] = sprintf('<tr><td>%s</td><td>%d</td></tr>', $average, $row->handicap);
        }

        $columns = '';
        foreach (array_chunk($cells, (int) ceil(count($cells) / 3)) as $chunk) {
            $columns .= '<table class="table table--numbers">'
                . '<thead><tr><th scope="col">' . $this->e($this->t->t('public.average')) . '</th><th scope="col">' . $this->e($this->t->t('common.hdc')) . '</th></tr></thead>'
                . '<tbody>' . implode('', $chunk) . '</tbody></table>';
        }

        $factor = str_replace('.', $this->t->t('number.decimal'), (string) $table->factor);
        $heading = $this->e($this->t->t('public.handicap_table'));
        $formula = $this->e($this->t->t('public.handicap_formula', ['target' => $table->targetScore, 'factor' => $factor, 'max' => $table->maxHandicap]));
        $season = $seasonName !== ''
            ? '<span class="card__sub">' . htmlspecialchars($seasonName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
            : '';

        return <<<HTML
            <section class="card">
                <div class="card__header"><h2>{$heading}</h2>{$season}</div>
                <p>{$formula}</p>
                <div class="handicap-table">{$columns}</div>
            </section>
            HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
