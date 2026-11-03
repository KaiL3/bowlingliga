<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Printing\PrintScoreSheetException;
use Bowlingliga\Domain\Printing\PrintScoreSheetService;
use Bowlingliga\Domain\Printing\PrintScoreSheetTeam;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Languages;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Printing\MpdfPrintScoreSheetRenderer;

/**
 * Controller of the admin area "printed score sheets" (task 35.4, R28.13,
 * R19).
 *
 * Binds the pure {@see PrintScoreSheetService} (task 30) to real raw data via
 * the {@see PrintScoreSheetData} adapter: for a fixture the header and team
 * data are loaded, the print-optimised HTML is generated and – if mPDF is
 * available – rendered into a PDF (R19.4); otherwise the print-optimised HTML
 * view is returned as fallback.
 *
 * If the lane assignment is missing for a regular matchday/positionday, the
 * domain service throws a {@see PrintScoreSheetException}; the controller then
 * returns an error result with a message for the user (R19.5). If the fixture
 * does not exist, an error result is returned as well.
 *
 * Access protection (R5.1) is enforced by the calling front controller.
 */
final class PrintController
{
    use ViewTexts;

    private readonly Formatter $format;

    private readonly ValueLabels $labels;

    /**
     * @param (callable(int, string): ?string)|null $entryQr QR code (image data URI) of the result entry
     *        of a fixture's side, null if the teams do not enter results (R37.2)
     */
    public function __construct(
        private readonly PrintScoreSheetDataSource $data,
        private readonly PrintScoreSheetService $service,
        private readonly MpdfPrintScoreSheetRenderer $renderer,
        private readonly mixed $entryQr = null,
        private readonly Translator $t = new Translator(),
        private readonly string $defaultLanguage = Languages::DEFAULT,
    ) {
        $this->format = new Formatter($t);
        $this->labels = new ValueLabels($t);
    }

    /**
     * Generates the printed score sheet of a fixture (R19).
     */
    /**
     * Selection page: choose the matchday of the season whose score sheets are
     * printed. The next matchday not yet played is preselected.
     */
    public function selection(?int $seasonId, ?string $error = null): string
    {
        $note = $error !== null
            ? sprintf('<p class="error" role="alert">%s</p>', $this->escape($error))
            : '';

        $matchdays = $seasonId !== null ? $this->data->matchdays($seasonId) : [];
        if ($matchdays === []) {
            return AdminComponents::pageHeader($this->plain('print.title'), $this->plain('admin.area.printing.short')) . $note
                . '<p class="notice">' . $this->text('print.no_matchdays', [], ['link' => '<a href="/admin/season-planning">' . $this->text('admin.area.planning.title') . '</a>']) . '</p>';
        }

        $preselection = null;
        foreach ($matchdays as $matchday) {
            if (! $matchday['played']) {
                $preselection = $matchday['id'];
                break;
            }
        }
        $preselection ??= $matchdays[array_key_last($matchdays)]['id'];

        $optionList = '';
        foreach ($matchdays as $matchday) {
            $optionList .= sprintf(
                '<option value="%d"%s>%s</option>',
                $matchday['id'],
                $matchday['id'] === $preselection ? ' selected' : '',
                $this->escape($this->matchdayLabel($matchday)),
            );
        }

        $seasonField = sprintf('<input type="hidden" name="season" value="%d">', $seasonId);
        $chosen = $this->language(null, $preselection);
        $languages = '';
        // Every language in its own name, as in the switch of the footer.
        foreach (Languages::names() as $code => $name) {
            $languages .= sprintf('<option value="%s"%s>%s</option>', $code, $code === $chosen ? ' selected' : '', AdminComponents::e($name));
        }
        $header       = AdminComponents::pageHeader($this->plain('print.title'), $this->plain('admin.area.printing.short'));

        return <<<HTML
            {$header}
            {$note}
            <section class="card">
            <div class="card__header"><h2>{$this->text('print.choose_matchday')}</h2><span class="card__sub">{$this->text('print.choose_hint')}</span></div>
            <form class="form" method="get" action="/admin/print" target="_blank" rel="noopener">
                {$seasonField}
                <div class="form__field">
                    <label for="print-matchday">{$this->text('public.matchday')}</label>
                    <select id="print-matchday" name="matchday" required>{$optionList}</select>
                </div>
                <div class="form__field">
                    <label for="print-language">{$this->text('print.language')}</label>
                    <select id="print-language" name="language">{$languages}</select>
                    <small>{$this->text('print.language_hint')}</small>
                </div>
                <button type="submit" class="button">{$this->text('print.create')}</button>
            </form>
            </section>
            HTML;
    }

    /**
     * Generates the score sheets of all fixtures of a matchday as one document
     * (one page per fixture, R19).
     */
    public function generateForMatchday(int $matchdayId, ?string $language = null): PrintScoreSheetResult
    {
        $service = $this->serviceIn($this->language($language, $matchdayId));
        $ids = $this->data->fixtureIds($matchdayId);
        $lotDraw = $ids === [] ? $this->data->lotDrawSheets($matchdayId) : null;
        if ($lotDraw !== null) {
            // Lot draw before the draw: one sheet per team with its half
            // pre-filled on the left; the opponent drawn on site is filled in
            // by hand on the right.
            $empty = new PrintScoreSheetTeam('', []);

            return $this->output($service->generateHtmlForMatchday(array_map(
                static fn (PrintScoreSheetTeam $team): array => [$lotDraw['header'], $team, $empty],
                $lotDraw['teams']
            )));
        }
        if ($ids === []) {
            return PrintScoreSheetResult::error(
                $this->t->t('print.no_fixtures')
            );
        }

        $fixtures = [];
        foreach ($ids as $position => $id) {
            $header = $this->data->header($id);
            $home = $this->data->homeTeam($id);
            $away = $this->data->awayTeam($id);

            if ($header === null || $home === null || $away === null) {
                return PrintScoreSheetResult::error($this->t->t('print.fixture_incomplete', ['number' => $position + 1]));
            }

            $fixtures[] = [$header, $this->withEntryQr($home, $id, 'home'), $this->withEntryQr($away, $id, 'away')];
        }

        try {
            $html = $service->generateHtmlForMatchday($fixtures);
        } catch (PrintScoreSheetException $e) {
            // R19.5: missing lane assignment for regular/positionday.
            return PrintScoreSheetResult::error($this->t->error($e));
        }

        return $this->output($html);
    }

    public function generate(int $fixtureId, ?string $language = null): PrintScoreSheetResult
    {
        $matchdayId = $this->data->matchdayOfFixture($fixtureId);
        $service = $this->serviceIn($this->language($language, $matchdayId));
        $header = $this->data->header($fixtureId);
        $home = $this->data->homeTeam($fixtureId);
        $away = $this->data->awayTeam($fixtureId);

        if ($header === null || $home === null || $away === null) {
            return PrintScoreSheetResult::error(
                $this->t->t('print.single_incomplete')
            );
        }

        try {
            $html = $service->generateHtml($header, $this->withEntryQr($home, $fixtureId, 'home'), $this->withEntryQr($away, $fixtureId, 'away'));
        } catch (PrintScoreSheetException $e) {
            // R19.5: missing lane assignment for regular/positionday.
            return PrintScoreSheetResult::error($this->t->error($e));
        }

        return $this->output($html);
    }

    /**
     * Language of a print: the one chosen in the form, otherwise the language
     * of the season, otherwise the installation's default. The sheet is read by
     * the players, so the administrator's own language does not count.
     */
    private function language(?string $requested, ?int $matchdayId): string
    {
        if (Languages::isSupported($requested)) {
            return (string) $requested;
        }
        $season = $matchdayId !== null ? $this->data->scoreSheetLanguage($matchdayId) : null;

        return Languages::isSupported($season) ? (string) $season : $this->defaultLanguage;
    }

    private function serviceIn(string $language): PrintScoreSheetService
    {
        return $this->service->withTexts(new CatalogPrintTexts(new Translator($language)));
    }

    /**
     * R19.4: PDF if mPDF is available; otherwise the HTML view as fallback.
     */
    private function withEntryQr(PrintScoreSheetTeam $team, int $fixtureId, string $side): PrintScoreSheetTeam
    {
        return is_callable($this->entryQr) ? $team->withEntryQr(($this->entryQr)($fixtureId, $side)) : $team;
    }

    private function output(string $html): PrintScoreSheetResult
    {
        if ($this->renderer->isAvailable()) {
            return PrintScoreSheetResult::pdf($this->renderer->renderToPdf($html));
        }

        return PrintScoreSheetResult::html($html);
    }

    /**
     * Label of a matchday in the selection, e.g.
     * "Matchday 3 · Wed, 24 Sep 2025 · 2 fixtures".
     *
     * @param array{number: int, date: string, type: string, played: bool, pairings: int} $matchday
     */
    private function matchdayLabel(array $matchday): string
    {
        $parts = [$this->plain('common.matchday_number', ['number' => $matchday['number']]), $this->format->dateLong($matchday['date'])];
        if ($matchday['type'] !== 'regular') {
            $parts[] = $this->labels->matchdayType($matchday['type']);
        }
        $parts[] = match (true) {
            $matchday['type'] === 'lot_draw' && $matchday['pairings'] === 0 => $this->plain('print.half_sheet'),
            $matchday['pairings'] === 1                                     => $this->plain('print.one_fixture'),
            default                                                         => $this->plain('print.fixtures', ['count' => $matchday['pairings']]),
        };
        if ($matchday['played']) {
            $parts[] = $this->plain('public.played');
        }

        return implode(' · ', $parts);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
