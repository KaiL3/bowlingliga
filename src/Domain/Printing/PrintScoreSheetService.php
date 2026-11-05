<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Printing;

use Bowlingliga\Domain\Points\BonusPointsScale;

/**
 * Pure calculation service for generating the print-optimised HTML of a
 * printed score sheet in the format of the league's paper form (R19, R32.3).
 *
 * The document is generated in A4 landscape (paper format via the mPDF
 * configuration) and reproduces the league's spreadsheet template: the two
 * teams stand side by side (home left, away right) and are structurally
 * identical, so that header, player table and evaluation footer are at the
 * same height. The title carries the league name (R38.4).
 *
 * Header layout per side (as in the template):
 * - Row 1: left "Spieltag", right "Startgeld … €".
 * - Row 2: left "Datum", right "Blindspieler … €".
 * - Row 3: "Bahn" (both sides).
 * - Row 4: "Team" with team name (both sides).
 *
 * The printed score sheet is a blank form: the fixed values are printed
 * (matchday, date, lane, team name, player names, AVG, HDC, number of games
 * "G"); the result fields (blind?, the three games, total net/gross) and the
 * whole evaluation footer stay empty and are filled in by hand on the
 * evening.
 *
 * Domain rules:
 * - Header with matchday number, date, lane per team, team names, entry fee
 *   and blind fee (R19.1, R19.7).
 * - Lane numbers only for a regular matchday/positionday; for a lot draw the
 *   lane field stays empty (R19.1, R32.3).
 * - If the lane assignment is missing for a regular matchday/positionday, no
 *   score sheet is generated; a {@see PrintScoreSheetException} is thrown
 *   instead (R19.5).
 * - Per team the team name and per roster player name, net average (AVG),
 *   HDC and number of games (G); missing values are printed empty (R19.2,
 *   R19.3, R19.6).
 *
 * {@see generateHtmlForMatchday()} combines the score sheets of all fixtures
 * of a matchday into one document with one page per fixture.
 *
 * All labels come from {@see PrintTexts} in the language of the print
 * ({@see withTexts()}); team and player names are printed as stored.
 */
final class PrintScoreSheetService
{
    /**
     * Minimum number of player rows per team in the form, so that there is
     * room for additions.
     */
    private const MIN_PLAYER_ROWS = 7;

    /**
     * Maximum number of rows the form pads to: with substitutions between
     * games up to 9 players of a team can take part (R21.2).
     */
    private const MAX_PLAYER_ROWS = 9;

    /**
     * @param string $leagueName Name of the league in the title (R38.4); the
     *                           default is that of a new installation (R38.2).
     */
    public function __construct(
        private readonly ?PrintTexts $texts = null,
        private readonly string $leagueName = 'Bowlingliga',
    ) {
    }

    /** The same service printing in the given language. */
    public function withTexts(PrintTexts $texts): self
    {
        return new self($texts, $this->leagueName);
    }

    /**
     * Generates the print-optimised HTML of a printed score sheet (R19).
     *
     * @throws PrintScoreSheetException if there is no lane assignment for a
     *         regular matchday/positionday (R19.5).
     */
    public function generateHtml(PrintScoreSheetHeader $header, PrintScoreSheetTeam $home, PrintScoreSheetTeam $away): string
    {
        return $this->document($this->renderSheet($header, $home, $away));
    }

    /**
     * Generates a document with the score sheets of all fixtures of a matchday,
     * one page per fixture (R19).
     *
     * @param list<array{0: PrintScoreSheetHeader, 1: PrintScoreSheetTeam, 2: PrintScoreSheetTeam}> $fixtures
     *        header, home and away team per fixture, in print order
     *
     * @throws PrintScoreSheetException if a fixture has no lane assignment
     *         (R19.5) or no fixture is passed.
     */
    public function generateHtmlForMatchday(array $fixtures): string
    {
        if ($fixtures === []) {
            throw PrintScoreSheetException::noFixtures();
        }

        $pages = [];
        foreach ($fixtures as [$header, $home, $away]) {
            $pages[] = $this->renderSheet($header, $home, $away);
        }

        return $this->document(implode("\n<pagebreak />\n", $pages));
    }

    /**
     * Renders one page (one score sheet): title and both team columns, each
     * with header, player table, evaluation block and its half of the bonus
     * points legend – as in the form.
     *
     * @throws PrintScoreSheetException if there is no lane assignment for a
     *         regular matchday/positionday (R19.5).
     */
    private function renderSheet(PrintScoreSheetHeader $header, PrintScoreSheetTeam $home, PrintScoreSheetTeam $away): string
    {
        // R19.5: without a lane assignment no printing for regular/positionday; for a
        // lot draw the missing lane number is the normal case.
        if (! $header->isLotDraw() && ! $header->hasLaneNumbers()) {
            throw PrintScoreSheetException::laneAssignmentMissing();
        }

        // Both sides get the same number of rows, so that the evaluation
        // blocks stay at the same height.
        $rowCount = min(self::MAX_PLAYER_ROWS, max(self::MIN_PLAYER_ROWS, $this->playerCount($home), $this->playerCount($away)));

        $homeColumn = $this->renderTeamColumn($header, $home, $header->laneLeft, false, $rowCount);
        $awayColumn = $this->renderTeamColumn($header, $away, $header->laneRight, true, $rowCount);

        return <<<HTML
            <div class="page">
                <div class="title">{$this->escape($this->text('print.sheet_title', ['league' => $this->leagueName]))}</div>
                <div class="left" style="float:left; width:48.5%">{$homeColumn}</div>
                <div class="right" style="float:right; width:48.5%">{$awayColumn}</div>
                <div style="clear:both"></div>
            </div>
            HTML;
    }

    /**
     * Wraps one or more pages in the HTML document including the print
     * stylesheet. Several pages are separated with `<pagebreak />` (mPDF page
     * break; via CSS in browser printing).
     */
    private function document(string $pages): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html lang="{$this->escape($this->texts()->language())}">
            <head>
                <meta charset="utf-8">
                <style>
                    /* Paper format A4 landscape via the mPDF configuration. */
                    @page { margin: 8mm 10mm; }
                    body { font-family: sans-serif; font-size: 8pt; color: #000; }
                    pagebreak { display: block; page-break-before: always; }
                    .title { text-align: center; font-weight: bold; font-size: 10pt; margin-bottom: 2mm; }
                    table.print-sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
                    table.print-sheet > tbody > tr > td { vertical-align: top; width: 50%; }
                    table.print-sheet > tbody > tr > td.left { padding-right: 5mm; }
                    table.print-sheet > tbody > tr > td.right { padding-left: 5mm; }

                    /* Header: labels right-aligned, values in boxes. */
                    table.header { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
                    table.header td { height: 7mm; padding: 0 1.5mm; vertical-align: middle; }
                    table.header td.label { text-align: right; font-size: 7pt; color: #333; }
                    table.header td.box { border: 1px solid #000; text-align: center; font-weight: bold; font-size: 14pt; }
                    table.header td.date { border: 1px solid #000; text-align: right; font-weight: bold; font-size: 8pt; }
                    table.header td.team { border: 1px solid #000; text-align: center; font-weight: bold; font-size: 15pt; }
                    table.header td.fee-label { font-weight: bold; font-size: 8pt; }
                    table.header td.fee { font-weight: bold; font-size: 8pt; text-align: right; }

                    /* Player table */
                    table.roster { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
                    table.roster th, table.roster td { border: 1px solid #000; padding: 0 1mm; text-align: center; height: 8.5mm; }
                    table.roster th { font-size: 7pt; font-weight: normal; height: 10mm; }
                    table.roster th.player { text-align: left; font-size: 10pt; font-weight: bold; padding-left: 1.5mm; }
                    table.roster th.hdc, table.roster td.hdc { font-weight: bold; }
                    table.roster td.name { text-align: left; font-size: 12pt; padding-left: 1.5mm; }
                    table.roster td.number { font-size: 8pt; white-space: nowrap; }
                    /* From 9 rows a little lower, so that everything fits on one page. */
                    table.roster-compact td { height: 7.2mm; }

                    /* Evaluation: only the label block has a frame. */
                    table.footer { width: 100%; border-collapse: collapse; }
                    table.footer td { height: 6.5mm; padding: 0 1.5mm; }
                    table.footer td.label { font-weight: bold; font-size: 7.5pt; border-left: 1px solid #000; border-right: 1px solid #000; }
                    table.footer tr.first td.label { border-top: 1px solid #000; }
                    table.footer tr.last td.label { border-bottom: 1px solid #000; }
                    table.footer td.field { border: 1px solid #000; }
                    table.signature { width: 100%; border-collapse: collapse; margin-top: 4mm; }
                    table.signature td { font-size: 7pt; vertical-align: bottom; height: 6mm; }
                    table.signature td.line { border-bottom: 1px solid #000; }

                    .entry-code { text-align: center; font-size: 6pt; line-height: 1.1; }
                    .legend { margin-top: 5mm; }
                    .legend__title { font-size: 7pt; margin-bottom: 1.5mm; }
                    .legend table { border-collapse: collapse; }
                    td.legend-cell { padding: 0.4mm 4mm 0.4mm 0; font-size: 8.5pt; font-weight: bold; }
                </style>
            </head>
            <body>
                {$pages}
            </body>
            </html>
            HTML;
    }

    /**
     * Renders a complete team column: header, player table, evaluation block,
     * signature and half of the legend.
     */
    private function renderTeamColumn(PrintScoreSheetHeader $header, PrintScoreSheetTeam $team, ?int $lane, bool $isAway, int $rowCount): string
    {
        return $this->renderHeaderWithEntryCode($header, $team, $lane, $isAway)
            . $this->renderRosterTable($team, $rowCount)
            . $this->renderFooter()
            . $this->renderLegend($header->bonus, $isAway);
    }

    /**
     * Header of a team column; with team entry the QR code of this team sits
     * next to it, so the sheet does not get taller (R37.2).
     */
    private function renderHeaderWithEntryCode(PrintScoreSheetHeader $header, PrintScoreSheetTeam $team, ?int $lane, bool $isAway): string
    {
        $html = $this->renderHeader($header, $team, $lane, $isAway);
        if ($team->entryQr === null) {
            return $html;
        }

        return '<div style="float:left; width:80%">' . $html . '</div>'
            . '<div class="entry-code" style="float:right; width:18%">'
            . '<img src="' . $this->escape($team->entryQr) . '" alt="" style="width:21mm; height:21mm">'
            . '<div>' . $this->escape($this->text('print.enter_online')) . '</div></div>'
            . '<div style="clear:both"></div>';
    }

    /**
     * Renders the header of a team column following the template grid:
     * - left: "Spieltag" (box), "Datum" (wide box),
     * - right: "Startgeld" and "Blindspieler" with amount,
     * - both sides: "Bahn" (box; empty for a lot draw, R32.3) and "Team" (wide
     *   box, team name large).
     */
    private function renderHeader(PrintScoreSheetHeader $header, PrintScoreSheetTeam $team, ?int $lane, bool $isAway): string
    {
        $laneValue = (! $header->isLotDraw() && $lane !== null) ? (string) $lane : '';

        if ($isAway) {
            $row1 = sprintf(
                '<tr><td style="width:16%%"></td><td style="width:14%%"></td><td class="fee-label" style="width:44%%; text-align:right">%s</td><td class="fee" style="width:26%%">%s €</td></tr>',
                $this->escape($this->text('print.entry_fee')),
                $this->money($header->entryFee),
            );
            $row2 = sprintf(
                '<tr><td></td><td></td><td class="fee-label" style="text-align:right">%s</td><td class="fee">%s €</td></tr>',
                $this->escape($this->text('print.blind_fee')),
                $this->money($header->blindFee),
            );
        } else {
            $row1 = sprintf(
                '<tr><td class="label" style="width:16%%">%s</td><td class="box" style="width:14%%">%s</td><td style="width:44%%"></td><td style="width:26%%"></td></tr>',
                $this->escape($this->text('print.matchday')),
                $header->matchdayNumber === null ? '' : (string) $header->matchdayNumber,
            );
            $row2 = sprintf(
                '<tr><td class="label">%s</td><td colspan="2" class="date">%s</td><td></td></tr>',
                $this->escape($this->text('print.date')),
                $this->escape($this->date($header->date)),
            );
        }

        $row3 = sprintf('<tr><td class="label">%s</td><td class="box">%s</td><td colspan="2"></td></tr>', $this->escape($this->text('print.lane')), $laneValue);
        $row4 = sprintf('<tr><td class="label">%s</td><td colspan="3" class="team">%s</td></tr>', $this->escape($this->text('common.team')), $this->escape($team->teamLabel));

        return '<table class="header">'
            . '<tbody>' . $row1 . $row2 . $row3 . $row4 . '</tbody></table>';
    }

    /**
     * Renders the player table with the column structure of the template:
     * player, AVG, HDC, G, blind?, the three games and total net/gross. The
     * table is padded with empty rows to $rowCount; only the fixed player values
     * appear, all result fields stay empty (R19.2, R19.3, R19.6). The ghost
     * team gets four ghost players with the target score as fixed result and
     * HDC 0 (R20.1, R20.2).
     */
    private function renderRosterTable(PrintScoreSheetTeam $team, int $rowCount): string
    {
        $rows = '';
        $quantity = 0;

        if ($team->ghostTargetScore !== null) {
            $z = $team->ghostTargetScore;
            for ($i = 0; $i < 4; $i++) {
                $rows .= sprintf(
                    '<tr><td class="name">%3$s</td><td class="number">%1$d</td><td class="number hdc">0</td><td class="number"></td><td></td>'
                    . '<td class="number">%1$d</td><td class="number">%1$d</td><td class="number">%1$d</td><td class="number">%2$d</td><td class="number">%2$d</td></tr>',
                    $z,
                    3 * $z,
                    $this->escape($this->text('print.ghost_player')),
                );
                $quantity++;
            }
        } else {
            foreach ($team->roster as $player) {
                $rows .= sprintf(
                    '<tr><td class="name">%s</td><td class="number">%s</td><td class="number hdc">%s</td><td class="number">%s</td>'
                    . '<td></td><td></td><td></td><td></td><td></td><td></td></tr>',
                    $this->escape($player->name),
                    $player->averageNet === null ? '' : $this->texts()->number($player->averageNet, 2),
                    $player->handicap === null ? '' : (string) $player->handicap,
                    $player->numberOfGames === 0 ? '' : (string) $player->numberOfGames,
                );
                $quantity++;
            }
        }

        for (; $quantity < $rowCount; $quantity++) {
            $rows .= '<tr><td class="name">&nbsp;</td><td></td><td></td><td></td><td></td>'
                . '<td></td><td></td><td></td><td></td><td></td></tr>';
        }

        // From 9 rows on lower rows, so that the sheet stays on one page.
        return sprintf('<table class="%s">', $rowCount >= self::MAX_PLAYER_ROWS ? 'roster roster-compact' : 'roster')
            . '<thead><tr>'
            . '<th class="player" style="width:19%">' . $this->escape($this->text('common.player')) . '</th><th text-rotate="90" style="width:9%">AVG</th>'
            . '<th class="hdc" style="width:7%">HDC</th><th style="width:8%">G</th><th text-rotate="90" style="width:4%">' . $this->escape($this->text('print.blind_question')) . '</th>'
            . '<th style="width:10%">- 1 -</th><th style="width:10%">- 2 -</th><th style="width:10%">- 3 -</th>'
            . '<th style="width:11%">' . $this->escape($this->text('results.total_net')) . '</th><th style="width:12%">' . $this->escape($this->text('results.total_gross')) . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    /**
     * Number of player rows a team needs: the roster, for the ghost team its
     * four fixed rows.
     */
    private function playerCount(PrintScoreSheetTeam $team): int
    {
        return $team->ghostTargetScore !== null ? 4 : count($team->roster);
    }

    /**
     * Renders the evaluation block: framed label block (net sum, total HDC,
     * gross sum, game points, bonus points, total points), next to it empty
     * fields under the columns -1-, -2-, -3- and total net, plus the signature
     * line.
     */
    private function renderFooter(): string
    {
        $labels = array_map(
            fn (string $key): string => $this->text('print.footer.' . $key),
            ['net_sum', 'hdc_total', 'gross_sum', 'game_points', 'bonus_points', 'total_points'],
        );
        $rows = '';
        $widths = ['19%', '28%', '10%', '10%', '10%', '11%', '12%'];
        foreach ($labels as $index => $label) {
            $cssClass = $index === 0 ? ' class="first"' : ($index === count($labels) - 1 ? ' class="last"' : '');
            $cells = '';
            // Fields to fill in under -1-, -2-, -3- and total net (columns 2–5);
            // bonus points and total points only under total net – as in the form.
            $fields = $index >= 4 ? [5] : [2, 3, 4, 5];
            foreach ($widths as $column => $width) {
                $style = $index === 0 ? sprintf(' style="width:%s"', $width) : '';
                if ($column === 0) {
                    $cells .= sprintf('<td class="label"%s>%s</td>', $style, $this->escape($label));
                } elseif (in_array($column, $fields, true)) {
                    $cells .= sprintf('<td class="field"%s></td>', $style);
                } else {
                    $cells .= sprintf('<td%s></td>', $style);
                }
            }
            $rows .= sprintf('<tr%s>%s</tr>', $cssClass, $cells);
        }

        return '<table class="footer">'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<table class="signature"><tbody><tr><td style="width:24%">' . $this->escape($this->text('print.signature')) . '</td><td class="line" style="width:56%">&nbsp;</td><td></td></tr></tbody></table>';
    }

    /**
     * Renders half of the bonus points scale of the season (R17.11): under
     * the home team the lower half of the levels (with heading), under the
     * away team the upper half – as in the form; with the default scale
     * 1–3 and 4–6 points. Without bonus points there is no legend.
     */
    private function renderLegend(BonusPointsScale $scale, bool $isAway): string
    {
        $levels = $scale->levels();
        if ($levels === []) {
            return '';
        }
        $half = intdiv(count($levels) + 1, 2);
        $levels = $isAway ? array_slice($levels, $half) : array_slice($levels, 0, $half);

        $rows = '';
        foreach ($levels as $level) {
            $pins = match (true) {
                $level['from'] === null => $this->text('print.legend.up_to', ['pins' => $level['to']]),
                $level['to'] === null   => $this->text('print.legend.over', ['pins' => $level['from']]),
                default                 => $this->text('print.legend.range', ['from' => $level['from'], 'to' => $level['to']]),
            };
            $points = $this->text($level['points'] === 1 ? 'print.legend.point' : 'print.legend.points', ['points' => $level['points']]);
            $rows .= sprintf('<tr><td class="legend-cell" style="width:38mm">%s</td><td class="legend-cell">%s</td></tr>', $this->escape($pins), $this->escape($points));
        }

        // The away part is level with the levels, not with the heading.
        $title = $isAway ? '<div class="legend__title">&nbsp;</div>' : '<div class="legend__title">' . $this->escape($this->text('print.legend.title')) . '</div>';

        return '<div class="legend">' . $title . '<table><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * The matchday date in the language of the print (German DD.MM.YYYY as in
     * the template). A value that is not Y-m-d is taken unchanged.
     */
    private function date(string $date): string
    {
        return $this->texts()->date($date);
    }

    /**
     * Formats an amount of money with two decimals (R19.7).
     */
    private function money(float $amount): string
    {
        return $this->texts()->number($amount, 2);
    }

    /** @param array<string, string|int|float> $params */
    private function text(string $key, array $params = []): string
    {
        return $this->texts()->text($key, $params);
    }

    private function texts(): PrintTexts
    {
        return $this->texts ?? throw new \LogicException('The printed score sheet needs its texts (withTexts()).');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
