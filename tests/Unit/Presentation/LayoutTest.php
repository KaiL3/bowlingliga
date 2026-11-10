<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\I18n\Translator;
use Bowlingliga\Presentation\Appearance;
use Bowlingliga\Presentation\Layout;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the responsive base layout (task 28.4).
 *
 * Validates: R36.1, R36.8
 *
 * Checks that every delivered page contains a viewport meta tag (R36.1) and
 * only includes a local stylesheet (no external CDN, R36.8). In addition title
 * and navigation escaping and a valid HTML5 skeleton are checked.
 */
final class LayoutTest extends TestCase
{
    private Layout $layout;

    protected function setUp(): void
    {
        $this->layout = new Layout();
    }

    public function testViewportMetaTagIsPresent(): void
    {
        $html = $this->layout->render('Titel', '<p>Inhalt</p>');

        self::assertStringContainsString(
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            $html,
            'Every page must contain a viewport meta tag (R36.1).'
        );
    }

    public function testOnlyLocalStylesheetIncluded(): void
    {
        $html = $this->layout->render('Titel', '<p>Inhalt</p>');

        self::assertStringContainsString('href="/assets/css/app.css?v=', $html, 'The stylesheet must be included locally (R36.8).');
        self::assertDoesNotMatchRegularExpression(
            '#<(link|script|img|iframe)\b[^>]*\b(href|src)="(https?:)?//#i',
            $html,
            'No external (CDN) assets may be included (R36.8); plain links are fine.'
        );
    }

    public function testOnlyALoggedInAdminGetsTheWayBackToTheAdminArea(): void
    {
        self::assertStringNotContainsString('header__admin', $this->layout->render('Titel', '<p>Inhalt</p>'));

        $html = $this->layout->render('Titel', '<p>Inhalt</p>', adminLink: true);
        self::assertStringContainsString('<a class="header__admin" href="/admin" title="Adminbereich" aria-label="Adminbereich"><svg', $html);
        self::assertLessThan(strpos($html, '</header>'), strpos($html, 'header__admin'), 'In the header.');

        $english = (new Layout(null, new Translator('en')))->render('Title', '<p>x</p>', adminLink: true);
        self::assertStringContainsString('aria-label="Admin area"', $english);
    }

    public function testTheFooterLinksTheSourceCode(): void
    {
        $html = $this->layout->render('Titel', '<p>Inhalt</p>');

        self::assertStringContainsString('<a class="footer__source" href="https://github.com/KaiL3/bowlingliga">Quellcode auf GitHub</a>', $html);
        self::assertStringContainsString('>Source code on GitHub</a>', (new Layout(null, new Translator('en')))->render('Title', '<p>x</p>'));
    }

    public function testFaviconsAreIncludedAndPresent(): void
    {
        $html = $this->layout->render('Titel', '<p>Inhalt</p>');
        $public = dirname(__DIR__, 3) . '/public';

        foreach (['/favicon.ico', '/assets/favicon.svg', '/assets/apple-touch-icon.png'] as $pathName) {
            self::assertStringContainsString('href="' . $pathName . '"', $html);
            self::assertFileExists($public . $pathName);
        }
    }

    public function testSkeletonAndLanguagePresent(): void
    {
        $html = $this->layout->render('Titel', '<p>Inhalt</p>');

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="de" data-style="lane" data-mode="auto">', $html, 'Standard-Darstellung: Lane, automatischer Modus.');
        self::assertStringContainsString('<meta charset="utf-8">', $html);
        self::assertStringContainsString('<p>Inhalt</p>', $html, 'The page content is inserted.');
    }

    public function testLeagueNameInHeaderAndFooterIsEscaped(): void
    {
        $html = (new Layout(null, new Translator('de'), '<b>Pin & Spare</b>'))->render('Titel', '<p>x</p>');

        self::assertSame(2, substr_count($html, '&lt;b&gt;Pin &amp; Spare&lt;/b&gt;'), 'Header and footer carry the league name (R38.4).');
        self::assertStringContainsString('<span>Bowlingliga</span>', $this->layout->render('Titel', '<p>x</p>'), 'Default of a new installation (R38.2).');
    }

    public function testTitleIsEscaped(): void
    {
        $html = $this->layout->render('<script>böse</script>', '<p>x</p>');

        self::assertStringNotContainsString('<script>böse</script>', $html, 'The title must be escaped.');
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testNavigationIsRenderedAndEscaped(): void
    {
        $html = $this->layout->render('Titel', '<p>x</p>', [
            ['href' => '/matchdays', 'text' => 'Spieltage'],
            ['href' => '/table', 'text' => 'Liga & Co'],
        ]);

        self::assertStringContainsString('href="/matchdays"', $html);
        self::assertStringContainsString('<span>Spieltage</span></a>', $html);
        self::assertStringContainsString('<span>Liga &amp; Co</span></a>', $html, 'The navigation text must be escaped.');
    }

    public function testSelectedAppearanceIsOnHtmlElement(): void
    {
        $html = (new Layout(Appearance::from('bento', 'dark')))->render('Titel', '<p>x</p>');

        self::assertStringContainsString('<html lang="de" data-style="bento" data-mode="dark">', $html);
        self::assertStringContainsString('href="/assets/fonts/plus-jakarta-sans.woff2"', $html, 'The font of the chosen style is preloaded (locally, R36.8).');
        self::assertStringContainsString('<option value="bento" selected>Bento</option>', $html);
        self::assertStringContainsString('<option value="dark" selected>Dunkel</option>', $html);
    }

    public function testToggleWorksWithoutJavaScriptAsForm(): void
    {
        $html = $this->layout->render('Titel', '<p>x</p>', [], '/table?season=2');

        self::assertStringContainsString('<form class="appearance" method="post" action="/appearance" data-appearance>', $html);
        self::assertStringContainsString('name="return_to" value="/table?season=2"', $html, 'Return to the requested page.');
        self::assertStringContainsString('<button type="submit" class="appearance__submit">Übernehmen</button>', $html);
    }

    public function testActiveNavigationItemIsMarked(): void
    {
        $navigation = [
            ['href' => '/', 'text' => 'Startseite', 'icon' => 'start'],
            ['href' => '/matchdays?season=2', 'text' => 'Spieltage', 'icon' => 'matchdays'],
            ['href' => '/table?season=2', 'text' => 'Ligatabelle', 'icon' => 'table'],
        ];

        $html = $this->layout->render('Titel', '<p>x</p>', $navigation, '/table?season=2');
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('href="/table?season=2" aria-current="page"', $html);
        self::assertStringContainsString('class="nav__icon"', $html, 'Navigationspunkte tragen Icons.');

        $detail = $this->layout->render('Titel', '<p>x</p>', $navigation, '/matchday/7');
        self::assertStringContainsString('href="/matchdays?season=2" aria-current="page"', $detail, 'The matchday detail view belongs to "Spieltage".');

        $team = $this->layout->render('Titel', '<p>x</p>', $navigation, '/team/3');
        self::assertStringContainsString('href="/table?season=2" aria-current="page"', $team, 'The team page belongs to "Ligatabelle".');
    }

    public function testAdminBarMarksTheAreaOfThePage(): void
    {
        $bar = [
            ['href' => '/admin', 'text' => 'Übersicht'],
            ['href' => '/admin/results', 'text' => 'Ergebnisse'],
            ['href' => '/admin/master-data', 'text' => 'Stammdaten'],
        ];

        $html = $this->layout->render('Titel', '<p>x</p>', [], '/admin/results/score-sheet?fixture=3', $bar);

        self::assertStringContainsString('<nav class="admin-bar" aria-label="Adminbereich">', $html);
        self::assertStringContainsString('href="/admin/results" aria-current="page">Ergebnisse</a>', $html, 'Sub pages belong to their area.');
        self::assertStringContainsString('href="/admin">Übersicht</a>', $html, 'The overview is active only on an exact match.');
        self::assertStringContainsString('action="/admin/logout"', $html, 'The bar offers the logout.');
    }

    public function testWithoutAdminEntriesNoAdminBar(): void
    {
        $html = $this->layout->render('Titel', '<p>x</p>', [], '/admin/login');

        self::assertStringNotContainsString('admin-bar', $html);
    }

    public function testWithoutNavigationNoNavElement(): void
    {
        $html = $this->layout->render('Titel', '<p>x</p>');

        self::assertStringNotContainsString('<nav', $html, 'Without navigation entries no nav element is rendered.');
    }

    public function testLogoLeadsToTheHomePageOfTheSelectedSeason(): void
    {
        $html = $this->layout->render('Titel', '<p>x</p>', [['href' => '/?season=1', 'text' => 'Startseite']]);

        self::assertStringContainsString('<a class="header__brand" href="/?season=1">', $html);
        self::assertStringContainsString('<a class="header__brand" href="/">', $this->layout->render('Titel', '<p>x</p>'));
    }

    // --- Season selection of the admin area -----------------------------------

    private const SEASONS = [['id' => 1, 'name' => 'Saison 2023/2024'], ['id' => 2, 'name' => 'Saison 2024/2025']];

    public function testAdminSeasonSelectionReturnsToTheAreaOfThePage(): void
    {
        $bar = [['href' => '/admin', 'text' => 'Übersicht'], ['href' => '/admin/results', 'text' => 'Ergebnisse']];
        $html = $this->layout->render('Titel', '<p>x</p>', [], '/admin/results/score-sheet?fixture=3', $bar, [
            'seasons' => self::SEASONS, 'selected' => 2, 'current' => 2,
        ]);

        self::assertStringContainsString('<form class="admin-season" method="get" action="/admin/results">', $html, 'The fixture belongs to the old season and is dropped.');
        self::assertStringContainsString('<option value="2" selected>Saison 2024/2025 (aktuell)</option>', $html);
        self::assertStringNotContainsString('Nicht die aktuelle Saison', $html);
    }

    public function testAnotherThanTheCurrentSeasonIsPointedOut(): void
    {
        $html = $this->layout->render('Titel', '<p>x</p>', [], '/admin', [['href' => '/admin', 'text' => 'Übersicht']], [
            'seasons' => self::SEASONS, 'selected' => 1, 'current' => 2,
        ]);

        self::assertStringContainsString('admin-season--other', $html);
        self::assertStringContainsString('Nicht die aktuelle Saison', $html);
        self::assertStringContainsString('<option value="1" selected>Saison 2023/2024</option>', $html);
    }

    public function testMasterDataKeepsTheOpenTabWhenSwitching(): void
    {
        $bar = [['href' => '/admin/master-data', 'text' => 'Stammdaten']];
        $html = $this->layout->render('Titel', '<p>x</p>', [], '/admin/master-data?tab=teams&season=1', $bar, [
            'seasons' => self::SEASONS, 'selected' => 1, 'current' => 2,
        ]);

        self::assertStringContainsString('action="/admin/master-data"><input type="hidden" name="tab" value="teams">', $html);
    }

    public function testWithoutLoginNoSeasonSelection(): void
    {
        $html = $this->layout->render('Titel', '<p>x</p>', [], '/admin/login');

        self::assertStringNotContainsString('admin-season', $html);
    }

    public function testFrameInTheVisitorsLanguageWithLanguageChoice(): void
    {
        $html = (new Layout(null, new Translator('en')))->render('Title', '<p>x</p>', [['href' => '/', 'text' => 'Home']], '/');

        self::assertStringContainsString('<html lang="en"', $html);
        self::assertStringContainsString('>Skip to content</a>', $html);
        self::assertStringContainsString('aria-label="Main navigation"', $html);
        self::assertStringContainsString('<select name="language">', $html);
        self::assertStringContainsString('<option value="en" lang="en" selected>English</option>', $html);
        self::assertStringContainsString('<option value="de" lang="de">Deutsch</option>', $html, 'Every language in its own name.');
        self::assertStringContainsString('<option value="dark">Dark</option>', $html);
    }
}
