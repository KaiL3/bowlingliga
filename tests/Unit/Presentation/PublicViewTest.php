<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\PublicSite\MatchdayDetail;
use Bowlingliga\Application\PublicSite\MatchdayFixture;
use Bowlingliga\Application\PublicSite\MatchdayListEntry;
use Bowlingliga\Application\PublicSite\ScoreSheetRow;
use Bowlingliga\Application\PublicSite\ScoreSheetTeamView;
use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\Domain\Honours\HonourCategory;
use Bowlingliga\Domain\Honours\HonourPlace;
use Bowlingliga\Domain\Honours\HonourPlacement;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\Honours\CategoryResult;
use Bowlingliga\Domain\AverageList\AverageListService;
use Bowlingliga\Domain\AverageList\AverageListPlayerInput;
use Bowlingliga\Domain\Table\TableService;
use Bowlingliga\Domain\Table\TeamStatistics;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Presentation\PublicView;
use Bowlingliga\Presentation\ScoreSheetCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the public views (task 28.1, step 1).
 *
 * Validates: R1.3, R2.3, R2.4, R2.15, R13.8, R13.10, R14.4, R14.5, R14.6, R14.7, R15.4, R16.1
 *
 * Checks the rendering of the matchday list (clickability, tooltip, "ohne
 * Ergebnisse"), the detail view (average lists, matchday table, missing
 * original score sheet), the league table and the honours including
 * visibility after the end date. The pure domain services are used for real
 * to build the test data.
 */
final class PublicViewTest extends TestCase
{
    private PublicView $view;
    private TableService $tableService;
    private AverageListService $averageLists;

    protected function setUp(): void
    {
        $this->view = new PublicView();
        $this->tableService = new TableService();
        $this->averageLists = new AverageListService();
    }

    // --- R14: Spieltagsliste ---

    public function testPlayedMatchdayIsLinked(): void
    {
        $html = $this->view->renderMatchdayList([
            new MatchdayListEntry(7, 1, '2026-01-15', 'Alpha', 'Beta', true),
        ]);

        self::assertStringContainsString('href="/matchday/7"', $html, 'Played matchdays are linked (R14.4).');
    }

    public function testUnplayedMatchdayWithTooltipAndWithoutResults(): void
    {
        $html = $this->view->renderMatchdayList([
            new MatchdayListEntry(8, 2, '2026-01-22', 'Gamma', 'Delta', false),
        ]);

        self::assertStringNotContainsString('href="/matchday/8"', $html, 'Matchdays not played are not linked (R14.5).');
        self::assertStringContainsString('title="Dieser Spieltag wurde noch nicht gespielt."', $html, 'Tooltip for matchdays not played (R14.6).');
        self::assertStringContainsString('ohne Ergebnisse', $html, 'Mark "ohne Ergebnisse" (R1.3).');
    }

    public function testEmptyMatchdayList(): void
    {
        $html = $this->view->renderMatchdayList([]);

        self::assertStringContainsString('keine Spieltage vorhanden', $html, 'Message for an empty season (R14.7).');
    }

    // --- R15 / R2: Detailansicht ---

    public function testScoreSheetShowsTeamSumsNetAndGrossPerGame(): void
    {
        $team = new ScoreSheetTeamView('Adler', [
            new ScoreSheetRow('Anna', false, [1 => 175, 2 => 195, 3 => 210], [1 => 187, 2 => 207, 3 => 222]),
            new ScoreSheetRow('Bernd', false, [1 => 170, 2 => 180, 3 => 140], [1 => 193, 2 => 203, 3 => 163]),
        ]);
        $method = new \ReflectionMethod($this->view, 'renderScoreSheetTeam');

        $html = (string) $method->invoke($this->view, $team);

        self::assertStringContainsString('<td class="sc-name">Team netto</td><td class="sc-hdc"></td><td class="sc-game">345</td><td class="sc-game">375</td><td class="sc-game">350</td>', $html);
        self::assertStringContainsString('<td class="sc-name">Team brutto</td><td class="sc-hdc"></td><td class="sc-game">380</td><td class="sc-game">410</td><td class="sc-game">385</td><td class="sc-net"></td><td class="sc-gross">1175</td>', $html);
    }

    public function testSingleBlindGameIsMarked(): void
    {
        $row = new ScoreSheetRow('Anna', false, [1 => 150, 2 => 160, 3 => 170], [1 => 174, 2 => 184, 3 => 194], [1 => false, 2 => false, 3 => true]);
        $team = new ScoreSheetTeamView('Adler', [$row]);
        $method = new \ReflectionMethod($this->view, 'renderScoreSheetTeam');

        $html = (string) $method->invoke($this->view, $team);

        self::assertStringContainsString('<td class="sc-game">150</td>', $html);
        self::assertStringContainsString('<td class="sc-game sc-game--blind" title="Spiel 3 blind">170<span class="sc-b" aria-label=" blind">B</span></td>', $html);
        self::assertStringNotContainsString('badge--blind', $html, 'The position itself is not blind.');
    }

    public function testSubstitutedPlayersShowOnlyTheirGames(): void
    {
        $team = new ScoreSheetTeamView('Adler', [
            new ScoreSheetRow('Anna', false, [1 => 175], [1 => 187]),
            new ScoreSheetRow('Eva', false, [2 => 160, 3 => 150], [2 => 180, 3 => 170]),
        ]);
        $method = new \ReflectionMethod($this->view, 'renderScoreSheetTeam');

        $html = (string) $method->invoke($this->view, $team);

        self::assertStringContainsString('<td class="sc-name">Anna</td><td class="sc-hdc"><span class="sc-label">HDC </span>12</td><td class="sc-game">175</td>'
            . '<td class="sc-game sc-game--none" title="Spiel 2 nicht gespielt">–</td><td class="sc-game sc-game--none" title="Spiel 3 nicht gespielt">–</td>', $html);
        self::assertStringContainsString('<td class="sc-name">Eva</td><td class="sc-hdc"><span class="sc-label">HDC </span>20</td>', $html);
        self::assertStringContainsString('<td class="sc-name">Team netto</td><td class="sc-hdc"></td><td class="sc-game">175</td><td class="sc-game">160</td><td class="sc-game">150</td>', $html);
    }
    public function testDetailWithoutOriginalScoreSheetShowsMessage(): void
    {
        $detail = $this->buildDetail(originalScoreSheetId: null);

        $html = $this->view->renderMatchdayDetail($detail);

        self::assertStringContainsString('kein hochgeladener Spielzettel verfügbar', $html, 'Message for a missing score sheet (R15.4).');
        self::assertStringContainsString('Tagestabelle', $html, 'The day table is shown (R15.3).');
    }

    public function testDetailWithOriginalScoreSheetLinked(): void
    {
        $detail = $this->buildDetail(originalScoreSheetId: 42);

        $html = $this->view->renderMatchdayDetail($detail);

        self::assertStringContainsString('/score-sheet/42', $html, 'An existing score sheet is linked (R15.1).');
    }

    public function testAverageListShowsEmptyHdcWithoutHandicap(): void
    {
        // A player without a frozen handicap -> HCP field empty (R2.15).
        $averageListHome = $this->averageLists->averageList([
            new AverageListPlayerInput('1', 'Anna', Gender::FEMALE, [150, 160, 170], null),
        ]);
        $detail = $this->buildDetail(originalScoreSheetId: null, averageListHome: $averageListHome);

        $html = $this->view->renderMatchdayDetail($detail);

        self::assertStringContainsString('Anna', $html);
        // Gross average "—" without a handicap.
        self::assertStringContainsString('—', $html, 'Without a handicap gross stays empty (R2.15).');
    }

    public function testEmptyAverageListShowsMessage(): void
    {
        $blank = $this->averageLists->averageList([]);
        $detail = $this->buildDetail(originalScoreSheetId: null, averageListHome: $blank);

        $html = $this->view->renderMatchdayDetail($detail);

        self::assertStringContainsString('keine Schnittwerte verfügbar', $html, 'Message for an empty average list (R2.4).');
    }

    // --- R16: Ligatabelle ---

    public function testLeagueTableShowsRanksAndTotalRow(): void
    {
        $table = $this->tableService->leagueTable([
            new TeamStatistics('1', 'Alpha', 20, 3000, 3200, 12),
            new TeamStatistics('2', 'Beta', 16, 2900, 3100, 12),
        ]);

        $html = $this->view->renderLeagueTable($table);

        self::assertStringContainsString('Ligatabelle', $html);
        self::assertStringContainsString('Alpha', $html);
        self::assertStringContainsString('class="total"', $html, 'The total row is rendered (R16.9).');
    }

    // --- R13.8 / R13.10: Honours-Sichtbarkeit ---

    public function testHonoursNotPublicBeforeEndDate(): void
    {
        $honours = new Honours([], false);

        $html = $this->view->renderHonours($honours);

        self::assertStringContainsString('erst nach Ablauf der Saison', $html, 'No public display before the end date (R13.8).');
    }

    public function testHonoursVisibleAfterEndDate(): void
    {
        $result = new CategoryResult(
            HonourCategory::HIGHEST_SERIES_NET,
            Gender::MALE,
            [new HonourPlace(1, [new HonourPlacement(5, 620, 'Max')])],
        );
        $honours = new Honours([$result], true);

        $html = $this->view->renderHonours($honours);

        self::assertStringContainsString('Höchste Serie (Netto)', $html, 'After the end date the honours are shown (R13.10).');
        self::assertStringContainsString('Max', $html);
        self::assertStringContainsString('620', $html);
    }

    public function testSamePlayerSeveralTimesInOnePlaceAppearsOnce(): void
    {
        $result = new CategoryResult(
            HonourCategory::HIGHEST_GAME_NET,
            Gender::FEMALE,
            [
                new HonourPlace(1, [new HonourPlacement(1, 185, 'Anna Berg')]),
                new HonourPlace(2, [
                    new HonourPlacement(1, 180, 'Anna Berg'),
                    new HonourPlacement(2, 180, 'Eva Funk'),
                    new HonourPlacement(1, 180, 'Anna Berg'),
                ]),
            ],
        );

        $html = $this->view->renderHonours(new Honours([$result], true));

        self::assertStringContainsString('<span class="honour__name">Anna Berg</span><span class="honour__value">185</span>', $html);
        self::assertStringContainsString('<span class="honour__name">Anna Berg, Eva Funk</span><span class="honour__value">180</span>', $html);
    }

    // --- Neues Design: Ergebnisblock, Datum, Kennzeichnungen, Startseite ---

    public function testDetailShowsGermanDateAndResultWithPoints(): void
    {
        $html = $this->view->renderMatchdayDetail($this->buildDetail(null));

        self::assertStringContainsString('Do, 15.01.2026', $html, 'Date in German format with weekday.');
        self::assertStringContainsString('<h1>Spieltag 1</h1>', $html);
        // Both teams: 4 × (170 + 180 + 190) = 2160 gross; every game 680 : 680 → 3 : 3
        // game points, bonus 2 each (2101–2200) → total 5 : 5 (R17).
        self::assertStringContainsString('<span class="result__pins">2160</span>', $html);
        self::assertStringContainsString('5<span> : </span>5', $html);
        self::assertStringContainsString('Spiel 1 <strong>680 : 680</strong>', $html);
    }

    public function testBlindPositionMarkedInScoreSheet(): void
    {
        $html = $this->view->renderMatchdayDetail($this->buildDetail(null));

        self::assertStringContainsString('Position 3 <span class="badge badge--blind">Blind</span>', $html);
        self::assertStringContainsString('<td class="sc-hdc"><span class="sc-label">HDC </span>20</td>', $html, 'HDC = Brutto − Netto.');
    }

    public function testGhostTeamOutOfCompetitionAndRankBadgesOnlyForRealTeams(): void
    {
        $table = $this->tableService->leagueTable([
            new TeamStatistics('g', 'Geisterteam', 18, 4560, 4560, 24, true),
            new TeamStatistics('1', 'Adler', 14, 3455, 3779, 24),
        ]);

        $html = $this->view->renderLeagueTable($table);

        self::assertStringContainsString('Geisterteam <span class="badge badge--neutral">außer Konkurrenz</span>', $html);
        self::assertStringContainsString('<tr class="table__row--ghost"><td><span class="rank">1</span>', $html, 'The ghost team gets no rank badge.');
        self::assertStringContainsString('<span class="rank rank--2">2</span>', $html);
        self::assertStringContainsString('<span class="table__extra">3779 Pins · Ø netto 143,96</span>', $html, 'Extra line for narrow screens.');
    }

    public function testHomePageShowsLastMatchdayTableAndSeasons(): void
    {
        $detail = $this->buildDetail(null);

        $html = $this->view->renderHomePage(
            '<section class="posts">Meldung</section>',
            $detail,
            $detail->matchdayTable,
            [['id' => 1, 'name' => '2024/25'], ['id' => 2, 'name' => '2025/26']],
            '2025/26',
        );

        self::assertStringContainsString('<p class="page-header__kicker">2025/26</p>', $html, 'Season name unchanged above the title.');
        self::assertStringContainsString('href="/matchday/7"', $html, 'Link to the last matchday.');
        self::assertStringContainsString('class="result"', $html);
        self::assertStringContainsString('<h2>Ligatabelle</h2>', $html);
        self::assertStringContainsString('Meldung', $html);
        self::assertLessThan(
            strpos($html, '2024/25'),
            strpos($html, '2025/26</a>'),
            'The newest season comes first.'
        );
    }

    public function testHomePageWithoutPlayedMatchday(): void
    {
        $html = $this->view->renderHomePage('', null, null, [], null);

        self::assertStringContainsString('Es wurde noch kein Spieltag gespielt.', $html);
        self::assertStringContainsString('Es sind noch keine Saisons angelegt.', $html);
    }

    public function testHomePageHeadingIsTheEscapedLeagueName(): void
    {
        $view = new PublicView(new ScoreSheetCalculator(), new Translator('de'), 'Pin & Spare Liga');

        self::assertStringContainsString('<h1>Pin &amp; Spare Liga</h1>', $view->renderHomePage('', null, null, [], null), 'R38.4');
        self::assertStringContainsString('<h1>Bowlingliga</h1>', $this->view->renderHomePage('', null, null, [], null), 'Default (R38.2).');
    }

    // --- Languages ---

    public function testPublicPagesInEnglishWithoutGermanLeftovers(): void
    {
        $view = new PublicView(new ScoreSheetCalculator(), new Translator('en'));
        $detail = $this->buildDetail(null);
        $honours = new Honours([new CategoryResult(HonourCategory::HIGHEST_SERIES_NET, Gender::MALE, [new HonourPlace(1, [new HonourPlacement(5, 620, 'Max')])])], true);
        $table = $this->tableService->leagueTable([
            new TeamStatistics('g', 'Ghosts', 18, 4560, 4560, 24, true),
            new TeamStatistics('1', 'Adler', 14, 3455, 3779, 24),
        ]);

        $html = $view->renderMatchdayDetail($detail)
            . $view->renderLeagueTable($table)
            . $view->renderHonours($honours)
            . $view->renderHonours(new Honours([], false))
            . $view->renderHomePage('', null, null, [], null)
            . $view->renderMatchdayList([new MatchdayListEntry(7, 1, '2026-01-15', null, null, false)])
            . $view->renderMatchdayList([]);

        self::assertStringContainsString('Thu, 15 Jan 2026', $html);
        self::assertStringContainsString('<h1>Matchday 1</h1>', $html);
        self::assertStringContainsString('Game 1 <strong>680 : 680</strong>', $html);
        self::assertStringContainsString('Ghosts <span class="badge badge--neutral">out of competition</span>', $html);
        self::assertStringContainsString('3779 pins · avg net 143.96', $html);
        self::assertStringContainsString('Highest series (net) <span class="card__sub">men</span>', $html);

        self::assertDoesNotMatchRegularExpression('/\b(public|common|nav|date)\.[a-z_.]+/', strip_tags($html), 'No key without its text.');
        $german = Translator::catalog('de');
        $english = Translator::catalog('en');
        foreach ($german as $key => $text) {
            if (mb_strlen($text) >= 8 && $text !== $english[$key] && ! str_contains($text, '{')) {
                self::assertStringNotContainsString(htmlspecialchars($text), $html, "German text of $key");
            }
        }
    }

    // --- Testdaten-Helfer ---

    private function buildDetail(
        ?int $originalScoreSheetId,
        ?\Bowlingliga\Domain\AverageList\AverageList $averageListHome = null,
    ): MatchdayDetail {
        $averageListHome ??= $this->averageLists->averageList([
            new AverageListPlayerInput('1', 'Anna', Gender::FEMALE, [150, 160, 170], 20),
        ]);
        $averageListAway = $this->averageLists->averageList([
            new AverageListPlayerInput('2', 'Ben', Gender::MALE, [140, 150, 160], 18),
        ]);
        $matchdayTable = $this->tableService->leagueTable([
            new TeamStatistics('1', 'Alpha', 4, 1400, 1500, 6),
            new TeamStatistics('2', 'Beta', 2, 1300, 1400, 6),
        ]);

        $fixture = new MatchdayFixture(
            70,
            '1/2',
            $this->buildScoreSheetTeam('Alpha'),
            $this->buildScoreSheetTeam('Beta'),
            $averageListHome,
            $averageListAway,
            $originalScoreSheetId,
        );

        return new MatchdayDetail(7, 1, '2026-01-15', [$fixture], $matchdayTable);
    }

    private function buildScoreSheetTeam(string $name): ScoreSheetTeamView
    {
        $positions = [];
        for ($pos = 1; $pos <= 4; $pos++) {
            $positions[] = new ScoreSheetRow(
                $pos <= 2 ? ('Spieler ' . $pos) : null,
                $pos > 2,
                [1 => 150, 2 => 160, 3 => 170],
                [1 => 170, 2 => 180, 3 => 190],
            );
        }

        return new ScoreSheetTeamView($name, $positions);
    }
}
