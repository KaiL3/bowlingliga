<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Printing;

use Bowlingliga\Domain\Points\BonusPointsScale;
use Bowlingliga\Domain\Printing\PrintScoreSheetException;
use Bowlingliga\Domain\Printing\PrintScoreSheetHeader;
use Bowlingliga\Domain\Printing\PrintScoreSheetPlayer;
use Bowlingliga\Domain\Printing\PrintScoreSheetService;
use Bowlingliga\Domain\Printing\PrintScoreSheetTeam;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Presentation\CatalogPrintTexts;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot/unit test for the {@see PrintScoreSheetService} (task 30.1, 35.4).
 *
 * Validates: R19.1, R19.2, R19.3, R19.5, R19.6, R19.7, R32.3
 *
 * Checks the print-optimised HTML in the format of the official score sheet:
 * header with matchday/date/lane per matchday type (regular and positionday
 * with lane numbers, lot draw without), entry fee/blind fee (R19.7), the
 * column structure of the player table including AVG/HDC/G (R19.2/R19.3),
 * empty fields for missing values (R19.6), the bonus points legend and the
 * error case of a missing lane assignment (R19.5).
 */
final class PrintScoreSheetServiceTest extends TestCase
{
    private PrintScoreSheetService $service;

    protected function setUp(): void
    {
        $this->service = new PrintScoreSheetService(new CatalogPrintTexts(new Translator('de')), 'Pin & Spare Liga');
    }

    public function testTitleCarriesTheDefaultLeagueNameWithoutAName(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5);

        $html = (new PrintScoreSheetService(new CatalogPrintTexts(new Translator('de'))))->generateHtml($header, $this->team(), $this->team());

        self::assertStringContainsString('Spielzettel Bowlingliga', $html, 'Default of a new installation (R38.2).');
    }

    private function team(): PrintScoreSheetTeam
    {
        return new PrintScoreSheetTeam('Alpha', [
            new PrintScoreSheetPlayer('Anna', 178.5, 20, 96),
        ]);
    }

    public function testScoreSheetInEnglishWithoutGermanLeftovers(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5);
        $ghost = new PrintScoreSheetTeam('Ghosts', [], 190);

        $html = $this->service->withTexts(new CatalogPrintTexts(new Translator('en')))->generateHtml($header, $this->team(), $ghost);

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('Score sheet Pin &amp; Spare Liga', $html, 'The league name survives the change of language (R38.4).');
        self::assertStringContainsString('Matchday:', $html);
        self::assertStringContainsString('15 Jan 2026', $html);
        self::assertStringContainsString('5.00 €', $html);
        self::assertStringContainsString('Ghost player', $html);
        self::assertStringContainsString('178.50', $html, 'Average with the English decimal point.');
        self::assertStringContainsString('over 2501 pins', $html);
        self::assertStringContainsString('1 point', $html);
        $german = Translator::catalog('de');
        $english = Translator::catalog('en');
        foreach ($german as $key => $text) {
            if (mb_strlen($text) >= 6 && $text !== $english[$key] && ! str_contains($text, '{')) {
                self::assertStringNotContainsString(htmlspecialchars($text), $html, "German text of $key");
            }
        }
    }

    public function testRegularWithLaneNumbers(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5);

        $html = $this->service->generateHtml($header, $this->team(), $this->team());

        self::assertStringContainsString('Spielzettel Pin &amp; Spare Liga', $html, 'League name escaped in the title (R38.4).');
        self::assertStringContainsString('Spieltag:', $html);
        self::assertStringContainsString('Datum:', $html);
        self::assertStringContainsString('15.01.2026', $html, 'Datum im deutschen Format TT.MM.JJJJ.');
        self::assertStringContainsString('5,00 €', $html, 'Entry fee with two decimals (R19.7).');
        self::assertStringContainsString('2,50 €', $html, 'Blind player fee with two decimals (R19.7).');
        // Both lanes (home/away) in their meta block.
        self::assertStringContainsString('>1</td>', $html, 'Lane of the home team.');
        self::assertStringContainsString('>2</td>', $html, 'Lane of the away team.');
    }

    /** R37.2: with team entry each team column carries its own QR code. */
    public function testEachTeamColumnShowsItsEntryCode(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5);

        $html = $this->service->generateHtml(
            $header,
            $this->team()->withEntryQr('data:image/svg+xml;base64,SEhIRQ=='),
            $this->team()->withEntryQr('data:image/svg+xml;base64,R0FTVA=='),
        );

        self::assertStringContainsString('<img src="data:image/svg+xml;base64,SEhIRQ=="', $html);
        self::assertStringContainsString('<img src="data:image/svg+xml;base64,R0FTVA=="', $html);
        self::assertSame(2, substr_count($html, 'Ergebnisse online eintragen'));
        self::assertStringNotContainsString('Ergebnisse online eintragen', $this->service->generateHtml($header, $this->team(), $this->team()));
    }

    public function testPositiondayWithLaneNumbers(): void
    {
        $header = new PrintScoreSheetHeader('2026-03-01', PrintScoreSheetHeader::TYPE_POSITIONDAY, 3, 4, 5.0, 2.5, 10);

        $html = $this->service->generateHtml($header, $this->team(), $this->team());

        self::assertStringContainsString('>3</td>', $html, 'A positionday shows the home lane (R19.1).');
        self::assertStringContainsString('>4</td>', $html, 'A positionday shows the away lane (R19.1).');
    }

    public function testLotDrawWithoutLaneNumbers(): void
    {
        $header = new PrintScoreSheetHeader('2026-02-01', PrintScoreSheetHeader::TYPE_LOT_DRAW, null, null, 5.0, 2.5, 7);

        $html = $this->service->generateHtml($header, $this->team(), $this->team());

        self::assertStringContainsString('01.02.2026', $html, 'The date is printed for a lot draw as well (R19.1).');
        // The lane row is present; the lane field stays empty for a lot draw (R32.3).
        self::assertStringContainsString('>Bahn</td>', $html);
    }

    public function testColumnStructureContainsAvgHdcAndG(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5);

        $html = $this->service->generateHtml($header, $this->team(), $this->team());

        self::assertStringContainsString('<th text-rotate="90" style="width:9%">AVG</th>', $html, 'AVG hochkant wie im Vordruck.');
        self::assertStringContainsString('<th class="hdc" style="width:7%">HDC</th>', $html);
        self::assertStringContainsString('<th style="width:8%">G</th>', $html);
        self::assertStringContainsString('<th text-rotate="90" style="width:4%">Blind?</th>', $html);
        self::assertStringContainsString('<td class="number hdc">20</td>', $html, 'HDC fett.');
        // AVG with two decimals as in the league's spreadsheet.
        self::assertStringContainsString('>178,50</td>', $html);
        self::assertStringContainsString('>20</td>', $html, 'HDC-Wert.');
        self::assertStringContainsString('>96</td>', $html, 'Column G (number of games before the matchday).');
    }

    public function testFooterBlockAndLegendPresent(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5);

        $html = $this->service->generateHtml($header, $this->team(), $this->team());

        self::assertStringContainsString('Nettosumme', $html);
        self::assertStringContainsString('Gesamt HDC', $html);
        self::assertStringContainsString('Bruttosumme', $html);
        self::assertStringContainsString('Spielpunkte', $html);
        self::assertStringContainsString('BonusPunkte', $html);
        self::assertStringContainsString('GesamtPunkte', $html);
        self::assertStringContainsString('Unterschrift Gegner', $html);
        self::assertStringContainsString('BonusPunkteWertung', $html);
        self::assertStringContainsString('über 2501 Pins', $html);
    }

    /**
     * R17.11: the legend follows the bonus scale of the season, lower half
     * under the home team, upper half under the away team.
     */
    public function testLegendFollowsTheBonusScaleOfTheSeason(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5, new BonusPointsScale(2000, 50, 4));

        $html = $this->service->generateHtml($header, $this->team(), $this->team());

        self::assertStringContainsString('2001 - 2050 Pins', $html);
        self::assertStringContainsString('über 2101 Pins', $html);
        self::assertStringNotContainsString('2501', $html);
        self::assertLessThan(strpos($html, '2051 - 2100'), strpos($html, '2001 - 2050'));

        $none = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5, new BonusPointsScale(2100, 100, 0));
        self::assertStringNotContainsString('<div class="legend">', $this->service->generateHtml($none, $this->team(), $this->team()), 'Without bonus points no legend.');
    }

    public function testMatchdayGeneratesOnePagePerFixture(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 3);
        $header2 = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 3, 4, 5.0, 2.5, 3);
        $home = new PrintScoreSheetTeam('Die Adler', []);
        $away = new PrintScoreSheetTeam('Falken', []);

        $html = (new PrintScoreSheetService(new CatalogPrintTexts(new Translator('de'))))->generateHtmlForMatchday([[$header, $home, $away], [$header2, $away, $home]]);

        self::assertSame(2, substr_count($html, '<div class="page">'), 'One page per fixture.');
        self::assertSame(1, substr_count($html, '<!DOCTYPE html>'), 'One common document.');
        self::assertSame(1, substr_count($html, '<pagebreak />'), 'mPDF page break between the fixtures.');
    }

    public function testMatchdayWithoutFixturesThrowsError(): void
    {
        $this->expectException(PrintScoreSheetException::class);
        (new PrintScoreSheetService(new CatalogPrintTexts(new Translator('de'))))->generateHtmlForMatchday([]);
    }

    /**
     * R20.1/R20.2: the ghost team appears with four ghost players, the target
     * score as fixed result and HDC 0.
     */
    public function testGhostTeamWithGhostPlayersAndTargetScore(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 3);
        $ghost = new PrintScoreSheetTeam('Geisterteam', [], 190);
        $home = new PrintScoreSheetTeam('Die Adler', []);

        $html = (new PrintScoreSheetService(new CatalogPrintTexts(new Translator('de'))))->generateHtml($header, $home, $ghost);

        self::assertSame(4, substr_count($html, '<td class="name">Geisterspieler</td>'));
        self::assertStringContainsString('<td class="number">190</td><td class="number hdc">0</td>', $html);
        self::assertStringContainsString('<td class="number">570</td><td class="number">570</td>', $html, 'Total net and gross = 3 × ghost score.');
    }

    /**
     * As on the form: room for at least seven players per team and the bonus
     * points legend split below both teams.
     */
    public function testSevenRowsAndSplitLegend(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 3);
        $html = (new PrintScoreSheetService(new CatalogPrintTexts(new Translator('de'))))->generateHtml($header, new PrintScoreSheetTeam('A', []), new PrintScoreSheetTeam('B', []));

        self::assertSame(14, substr_count($html, '<td class="name">'), 'Je Team sieben Spielerzeilen.');
        self::assertSame(2, substr_count($html, '<div class="legend">'), 'Legend per team half.');
        // Fields to fill in: 4 rows × 4 fields (-1-, -2-, -3-, total net) + one field each for bonus and total points, per team.
        self::assertSame(2 * 18, substr_count($html, '<td class="field"'), 'Fill-in fields of the evaluation as in the paper form.');
        self::assertLessThan(strpos($html, '2301 - 2400 Pins'), strpos($html, '2201 - 2300 Pins'), 'Untere Stufen links, obere rechts.');
    }

    /**
     * Both sides get the same number of rows – that of the larger roster, at
     * least 7, padded up to at most 9 –, so that the evaluation blocks stay
     * level. Three-digit numbers of games do not wrap.
     */
    public function testBothTeamsGetTheSameNumberOfRowsUpToNine(): void
    {
        $header  = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 3, 4, 15.0, 7.5, 1);
        $players = static fn (int $count): array => array_map(
            static fn (int $i): PrintScoreSheetPlayer => new PrintScoreSheetPlayer('Spieler ' . $i, 150.0, 30, 120),
            range(1, $count),
        );

        $html = $this->service->generateHtml($header, new PrintScoreSheetTeam('A', $players(8)), new PrintScoreSheetTeam('B', $players(7)));
        self::assertSame(16, substr_count($html, '<td class="name">'), 'Both sides 8 rows (larger roster).');
        self::assertStringNotContainsString('<table class="roster roster-compact">', $html);

        $html = $this->service->generateHtml($header, new PrintScoreSheetTeam('A', $players(9)), new PrintScoreSheetTeam('Geisterteam', [], 190));
        self::assertSame(18, substr_count($html, '<td class="name">'), 'Ghost team padded to 9 rows as well.');
        self::assertSame(2, substr_count($html, '<table class="roster roster-compact">'), 'Lower rows from 9 rows on, so that the sheet stays on one page.');

        $html = $this->service->generateHtml($header, new PrintScoreSheetTeam('A', $players(3)), new PrintScoreSheetTeam('B', $players(12)));
        self::assertSame(12 + 9, substr_count($html, '<td class="name">'), 'Padding stops at 9 rows; a larger roster is printed completely.');

        self::assertStringContainsString('table.roster td.number { font-size: 8pt; white-space: nowrap; }', $html);
        self::assertStringContainsString('<th style="width:8%">G</th>', $html);
    }

    public function testMissingLaneAssignmentForRegularThrowsError(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, null, null, 5.0, 2.5, 5);

        $this->expectException(PrintScoreSheetException::class);
        $this->service->generateHtml($header, $this->team(), $this->team());
    }

    public function testMissingValuesStayEmpty(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5);
        $team = new PrintScoreSheetTeam('Beta', [
            new PrintScoreSheetPlayer('Ben', null, null, 0),
        ]);

        $html = $this->service->generateHtml($header, $team, $this->team());

        self::assertStringContainsString('Ben', $html);
        // The name is printed; AVG/HDC/G stay empty (R19.6: 0 games -> empty).
        self::assertStringContainsString('<td class="name">Ben</td><td class="number"></td><td class="number hdc"></td><td class="number"></td>', $html);
    }

    public function testTeamNamesAreOutput(): void
    {
        $header = new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5, 5);

        $html = $this->service->generateHtml($header, $this->team(), new PrintScoreSheetTeam('Beta', [
            new PrintScoreSheetPlayer('Ben', 150.0, 15, 30),
        ]));

        self::assertStringContainsString('Alpha', $html);
        self::assertStringContainsString('Beta', $html);
        self::assertStringContainsString('Anna', $html);
        self::assertStringContainsString('Ben', $html);
    }
}
