<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\Application\PublicSite\TeamAverageList;
use Bowlingliga\Domain\AverageList\AverageListService;
use Bowlingliga\Domain\AverageList\AverageListPlayerInput;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Application\PublicSite\MatchdayDetail;
use Bowlingliga\Application\PublicSite\MatchdayFixture;
use Bowlingliga\Application\PublicSite\ScoreSheetRow;
use Bowlingliga\Application\PublicSite\ScoreSheetTeamView;
use Bowlingliga\Domain\Table\TableService;
use Bowlingliga\Domain\Table\TeamStatistics;
use Bowlingliga\Presentation\PublicView;
use Bowlingliga\Presentation\PublicController;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see PublicController} (task 28.1, step 6).
 *
 * Validates: R1.5, R15.1, R15.5, R16.1, R13.10
 *
 * Checks the visibility and error logic: detail view only for played
 * matchdays (R15.5), a message for a non-existent/unretrievable matchday
 * (R1.5), and the delivery of league table and honours. The data access is an
 * in-memory fake.
 */
final class PublicControllerTest extends TestCase
{
    private function controller(FixedPublicDataReader $reader): PublicController
    {
        return new PublicController($reader, new PublicView());
    }

    /** R14.8: next matchday as first tab, linked from the home page. */
    public function testNextMatchdayIsTheFirstTabAndLinkedFromTheHomePage(): void
    {
        $reader = new FixedPublicDataReader(
            matchdayList: [new \Bowlingliga\Application\PublicSite\MatchdayListEntry(5, 3, '2026-10-21', 'A', 'B', false)],
            nextMatchday: new \Bowlingliga\Application\PublicSite\UpcomingMatchday(5, 3, '2026-10-21', 'regular', [
                ['home' => 'Pin <Busters>', 'away' => 'Strike Force', 'home_lane' => 3, 'away_lane' => 4],
            ]),
        );

        $list = $this->controller($reader)->matchdayList(1);
        self::assertStringContainsString('id="matchdays-tab-next" role="tab" aria-controls="matchdays-panel-next" aria-selected="true">Nächster Spieltag', $list);
        self::assertStringContainsString('>Alle Spieltage</button>', $list);
        self::assertStringContainsString('<th scope="col">Bahn</th><th scope="col">Team</th><th scope="col">Bahn</th><th scope="col">Team</th>', $list);
        self::assertStringContainsString('<tr><td class="table__lanes">3</td><td>Pin &lt;Busters&gt;</td><td class="table__lanes">4</td><td>Strike Force</td></tr>', $list);
        self::assertStringContainsString('Mi, 21.10.2026', $list);

        $home = $this->controller($reader)->homePage(1, '', [['id' => 1, 'name' => 'Saison']]);
        self::assertStringContainsString('<a href="/matchdays?season=1">Paarungen und Bahnen von Spieltag 3 ansehen</a>', $home);
    }

    public function testWithoutNextMatchdayThereAreNoTabs(): void
    {
        $reader = new FixedPublicDataReader(matchdayList: [new \Bowlingliga\Application\PublicSite\MatchdayListEntry(5, 3, '2026-10-21', 'A', 'B', true)]);

        self::assertStringNotContainsString('data-tabs', $this->controller($reader)->matchdayList(1));
        self::assertStringNotContainsString('Nächster Spieltag', $this->controller($reader)->homePage(1, '', [['id' => 1, 'name' => 'Saison']]));
    }

    public function testSpecialMatchdaysExplainWhyThereAreNoFixturesYet(): void
    {
        foreach (['positionday' => 'Tabellenstand', 'lot_draw' => 'vor Ort ausgelost', 'regular' => 'noch nicht fest'] as $type => $text) {
            $reader = new FixedPublicDataReader(
                matchdayList: [new \Bowlingliga\Application\PublicSite\MatchdayListEntry(5, 10, '2026-10-21', null, null, false)],
                nextMatchday: new \Bowlingliga\Application\PublicSite\UpcomingMatchday(5, 10, '2026-10-21', $type, []),
            );

            self::assertStringContainsString($text, $this->controller($reader)->matchdayList(1), $type);
        }
    }

    public function testDetailOfUnplayedMatchdayShowsMessage(): void
    {
        $reader = new FixedPublicDataReader(played: [5 => false]);

        $html = $this->controller($reader)->matchdayDetail(5);

        self::assertStringContainsString('noch nicht gespielt', $html, 'Matchdays not played have no detail view (R15.5).');
    }

    public function testDetailUnavailableShowsMessage(): void
    {
        // played, but no detail data exists -> R1.5.
        $reader = new FixedPublicDataReader(played: [5 => true], details: []);

        $html = $this->controller($reader)->matchdayDetail(5);

        self::assertStringContainsString('nicht verfügbar', $html, 'If the data cannot be read a message appears (R1.5).');
    }

    public function testDetailOfPlayedMatchdayIsShown(): void
    {
        $detail = $this->buildDetail();
        $reader = new FixedPublicDataReader(played: [7 => true], details: [7 => $detail]);

        $html = $this->controller($reader)->matchdayDetail(7);

        self::assertStringContainsString('Spieltag 1', $html);
        self::assertStringContainsString('Tagestabelle', $html, 'The detail view contains the day table (R15.3).');
    }

    public function testLeagueTableUnavailableShowsMessage(): void
    {
        $reader = new FixedPublicDataReader(leagueTable: null);

        $html = $this->controller($reader)->leagueTable(1);

        self::assertStringContainsString('nicht verfügbar', $html);
    }

    public function testLeagueTableIsShown(): void
    {
        $table = (new TableService())->leagueTable([
            new TeamStatistics('1', 'Alpha', 20, 3000, 3200, 12),
        ]);
        $reader = new FixedPublicDataReader(leagueTable: $table);

        $html = $this->controller($reader)->leagueTable(1);

        self::assertStringContainsString('Ligatabelle', $html);
        self::assertStringContainsString('Alpha', $html);
    }

    public function testTeamPageWithoutPlayedMatchday(): void
    {
        $reader = new FixedPublicDataReader(teamAverageLists: [
            7 => new TeamAverageList(7, 3, 'Pins & Co', null, null, (new AverageListService())->averageList([])),
        ]);

        $html = $this->controller($reader)->teamAverageList(7);

        self::assertStringContainsString('<h1>Pins &amp; Co</h1>', $html);
        self::assertStringContainsString('Noch kein Spieltag gespielt', $html);
        self::assertStringContainsString('Es wurde noch kein Spieltag gespielt.', $html);
        self::assertStringContainsString('href="/table?season=3"', $html);
    }

    public function testTeamPageUnknownTeam(): void
    {
        $html = $this->controller(new FixedPublicDataReader())->teamAverageList(7);

        self::assertStringContainsString('nicht gefunden', $html);
    }

    public function testHonoursAreShown(): void
    {
        $reader = new FixedPublicDataReader(honours: new Honours([], true));

        $html = $this->controller($reader)->honours(1);

        self::assertStringContainsString('Ehrungen', $html);
    }

    private function buildDetail(): MatchdayDetail
    {
        $averageLists = new AverageListService();
        $tables = new TableService();

        $home = $averageLists->averageList([
            new AverageListPlayerInput('1', 'Anna', Gender::FEMALE, [150, 160, 170], 20),
        ]);
        $away = $averageLists->averageList([
            new AverageListPlayerInput('2', 'Ben', Gender::MALE, [140, 150, 160], 18),
        ]);
        $matchdayTable = $tables->leagueTable([
            new TeamStatistics('1', 'Alpha', 4, 1400, 1500, 6),
            new TeamStatistics('2', 'Beta', 2, 1300, 1400, 6),
        ]);

        $positions = [];
        for ($pos = 1; $pos <= 4; $pos++) {
            $positions[] = new ScoreSheetRow($pos <= 2 ? ('Spieler ' . $pos) : null, $pos > 2, [1 => 150, 2 => 160, 3 => 170], [1 => 170, 2 => 180, 3 => 190]);
        }
        $sheetHome = new ScoreSheetTeamView('Alpha', $positions);
        $sheetAway = new ScoreSheetTeamView('Beta', $positions);

        $fixture = new MatchdayFixture(70, '1/2', $sheetHome, $sheetAway, $home, $away, null);

        return new MatchdayDetail(7, 1, '2026-01-15', [$fixture], $matchdayTable);
    }
}
