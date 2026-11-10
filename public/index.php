<?php

declare(strict_types=1);

/**
 * Front controller of the bowling league website (composition root, task
 * 34.3).
 *
 * Entry point of all HTTP requests. It establishes the database connection,
 * denies access if the connection is missing (R4.4), assembles the
 * repositories, aggregators and controllers (composition root) and passes the
 * request on to the router, which separates public (reading) from
 * administrative (writing) routes.
 *
 * The public routes bind the views to real raw data via the production
 * {@see PublicDataRepository} (R1, R2, R13–R16). The admin area is only
 * reachable after login (R5.1, R28.6); its domain wiring follows further
 * down.
 */

use Bowlingliga\Application\Aggregation\HonoursAggregator;
use Bowlingliga\Application\Aggregation\AverageListAggregator;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Persistence\Aggregation\HonoursRawDataRepository;
use Bowlingliga\Persistence\Aggregation\AverageListRawDataRepository;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Application\AdminAccountManagement;
use Bowlingliga\Application\Cms\RulesManagement;
use Bowlingliga\Application\Cms\HomePagePostManagement;
use Bowlingliga\Domain\Handicap\HandicapTableService;
use Bowlingliga\Application\Import\PlayerCsvParser;
use Bowlingliga\Application\Import\PlayerImport;
use Bowlingliga\Application\PreviousSeasonRecordManagement;
use Bowlingliga\Persistence\Import\PlayerImportRepository;
use Bowlingliga\Persistence\PreviousSeasonContextRepository;
use Bowlingliga\Persistence\PreviousSeasonRecordRepository;
use Bowlingliga\Presentation\PlayerImportController;
use Bowlingliga\Presentation\PlayerImportView;
use Bowlingliga\Persistence\Cms\RepositoryCmsReader;
use Bowlingliga\Presentation\CmsEditController;
use Bowlingliga\Presentation\CmsEditView;
use Bowlingliga\Application\League\LeagueSettings;
use Bowlingliga\Persistence\LeagueSettingsRepository;
use Bowlingliga\Presentation\LeagueSettingsController;
use Bowlingliga\Presentation\LeagueSettingsView;
use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningManagement;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Persistence\SeasonPlanRepository;
use Bowlingliga\Persistence\SeasonPlanning\SeasonPlanningDataRepository;
use Bowlingliga\Presentation\SeasonPlanningController;
use Bowlingliga\Presentation\SeasonPlanningView;
use Bowlingliga\Presentation\HonoursPreviewController;
use Bowlingliga\Application\ResultEntry\BlindPlayerOrder;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Application\ResultEntry\OriginalScoreSheetStorage;
use Bowlingliga\Application\ResultEntry\ScoreSheetOverview;
use Bowlingliga\Application\ResultEntry\VirtualScoreSheetService;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\Domain\CurrentSeason;
use Bowlingliga\Domain\BlindPlayerService;
use Bowlingliga\Persistence\CacheRepository;
use Bowlingliga\Persistence\ResultRepository;
use Bowlingliga\Persistence\ResultEntry\EntryDataRepository;
use Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository;
use Bowlingliga\Persistence\ScoreSheetRepository;
use Bowlingliga\Persistence\OverviewRepository;
use Bowlingliga\Presentation\ResultEntryController;
use Bowlingliga\Presentation\ResultEntryView;
use Bowlingliga\Application\SeasonAdministration;
use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\MatchdayManagement;
use Bowlingliga\Application\TeamManagement;
use Bowlingliga\Persistence\LanePairRepository;
use Bowlingliga\Domain\Printing\PrintScoreSheetService;
use Bowlingliga\Printing\MpdfPrintScoreSheetRenderer;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\Printing\PrintScoreSheetData;
use Bowlingliga\Persistence\RosterRepository;
use Bowlingliga\Persistence\PublicSite\PublicDataRepository;
use Bowlingliga\Persistence\PublicSite\ScoreSheetDisplayRepository;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;
use Bowlingliga\Persistence\SeasonConfigRepository;
use Bowlingliga\Persistence\SeasonRepository;
use Bowlingliga\Persistence\PlayerRepository;
use Bowlingliga\Persistence\RulesRepository;
use Bowlingliga\Persistence\MatchdayRepository;
use Bowlingliga\Persistence\HomePagePostRepository;
use Bowlingliga\Persistence\FixtureRepository;
use Bowlingliga\Persistence\TeamRepository;
use Bowlingliga\Persistence\TeamSeasonRepository;
use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Presentation\AdminView;
use Bowlingliga\Presentation\CmsView;
use Bowlingliga\Presentation\PrintController;
use Bowlingliga\Presentation\Appearance;
use Bowlingliga\Presentation\AccountController;
use Bowlingliga\Presentation\AccountView;
use Bowlingliga\Presentation\Layout;
use Bowlingliga\Presentation\PublicNavigation;
use Bowlingliga\Presentation\PublicView;
use Bowlingliga\Presentation\PublicController;
use Bowlingliga\Presentation\Router;
use Bowlingliga\Presentation\MasterDataController;
use Bowlingliga\Presentation\MasterDataView;
use Bowlingliga\Security\AuthModule;
use Bowlingliga\Security\CsrfTokenHelper;
use Bowlingliga\Security\AccountManagement;
use Bowlingliga\Security\LoginStatus;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\PhpSessionStore;
use Bowlingliga\Security\TotpVerifier;
use Bowlingliga\Security\TotpSecretEncryption;
use Bowlingliga\Security\TransportSecurity;

require dirname(__DIR__) . '/vendor/autoload.php';

// Security headers for all responses: no MIME sniffing, no embedding in
// foreign pages, scripts and content only from the own domain. Inline styles
// stay allowed (print-optimised HTML view); inline scripts do not.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
    . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
// The league never needs location and microphone. The camera stays allowed,
// because the score sheet upload lets the phone take a photo directly. Images
// from blob: are the chosen photo while it is cropped (R3.21).
header('Permissions-Policy: geolocation=(), microphone=()');
// HTTPS only from the second visit on, so nobody in the same network can
// intercept the first unencrypted request (only sent over HTTPS).
$strictTransportSecurity = TransportSecurity::strictTransportSecurityHeader($_SERVER);
if ($strictTransportSecurity !== null) {
    header($strictTransportSecurity);
}

// BOWLINGLIGA_CONFIG can point to a different configuration file,
// e.g. for the integration test against the test database (task 35.7).
$configFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
$config = is_file($configFile)
    ? require $configFile
    : require dirname(__DIR__) . '/config/database.php.example';

// Language of the pages: the visitor's choice (cookie), on the first visit the
// browser's preference (Accept-Language), otherwise the installation's default
// (`language` in the configuration).
$translator = new \Bowlingliga\I18n\Translator(\Bowlingliga\I18n\LanguageNegotiator::choose(
    $_COOKIE[\Bowlingliga\I18n\LanguageNegotiator::COOKIE] ?? null,
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
    (string) ($config['language'] ?? \Bowlingliga\I18n\Languages::DEFAULT),
));
header('Content-Language: ' . $translator->language());
header('Vary: Accept-Language, Cookie');
// Language of the installation, e.g. for printed score sheets of a season without one.
$defaultLanguage = \Bowlingliga\I18n\Languages::isSupported($config['language'] ?? null) ? (string) $config['language'] : \Bowlingliga\I18n\Languages::DEFAULT;

$database = new Database($config);

// R4.4: if the database connection cannot be established
// (within 10 s), read and write access are denied and an error message
// is shown that points to the missing database connection.
try {
    $database->pdo();
} catch (DatabaseConnectionException $exception) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo $translator->t('error.database_unavailable');

    return;
}

// --- Composition Root: Repositories, Aggregatoren, Controller ------------
$seasons       = new SeasonRepository($database);
$matchdays     = new MatchdayRepository($database);
$teamFixtures = new FixtureRepository($database);
$teamSeasons   = new TeamSeasonRepository($database);
// Original score sheets lie as files in `score_sheet_dir` (R3.15).
$scoreSheet   = new OriginalScoreSheetRepository(
    $database,
    \Bowlingliga\Persistence\FileSystemBlobStore::forScoreSheets($config, dirname(__DIR__))
);
$homePage    = new HomePagePostRepository($database);
$rules   = new RulesRepository($database);
// Name of the league for header, home page, print and 2FA (R38), read once per request.
$leagueStore = new LeagueSettingsRepository($database);
$leagueName  = LeagueSettings::nameFrom($leagueStore);

// --- Composition root: admin area access (auth) ----------------------------
// The admin area is only reachable after a successful login
// (R28.8). The domain wiring of the individual admin areas
// follows below; here the access (login/logout), the
// access protection (R28.9) and the admin area overview (R28.10) are provided.
$session    = new PhpSessionStore();
$adminView  = new AdminView($translator);
$csrf       = new CsrfTokenHelper($session);
$adminAccounts = new AdminAccountRepository($database);

// The server-side key for the encrypted TOTP secret lives outside
// the database (R23.9) and is provided via the configuration as a base64-encoded
// 32 byte sequence (former key name `totp_key` still accepted).
$totpKey = base64_decode((string) ($config['totp_key'] ?? $config['totp_schluessel'] ?? ''), true) ?: '';

$auth = new AuthModule(
    $adminAccounts,
    new PasswordHasher(),
    new TotpSecretEncryption($totpKey),
    new TotpVerifier(),
    $session,
    new \Bowlingliga\Persistence\PdoTransaction($database),
);

/**
 * Whether this request may see player data (R40.2, R40.3): always when the
 * league makes it public, otherwise only for a logged-in admin. Without a
 * session cookie no session is started, so anonymous visitors get none.
 */
$playersVisible = static function () use ($leagueStore, $auth): bool {
    static $visible = null;

    return $visible ??= $leagueStore->playersPublic()
        || (isset($_COOKIE[session_name()]) && $auth->checkWriteAccess());
};

$dataReader = new PublicDataRepository(
    $seasons,
    $matchdays,
    $teamFixtures,
    $teamSeasons,
    $scoreSheet,
    new LanePairRepository($database),
    new ScoreSheetDisplayRepository($database),
    new TableAggregator(new TableRawDataRepository($database)),
    new AverageListAggregator(new AverageListRawDataRepository($database)),
    new HonoursAggregator(new HonoursRawDataRepository($database)),
);

// Style and colour mode chosen by the visitor (cookies, display values only).
$layout     = new Layout(Appearance::fromCookies($_COOKIE), $translator, $leagueName);
$navigation = new PublicNavigation($translator);
$controller = new PublicController($dataReader, new PublicView(new \Bowlingliga\Presentation\ScoreSheetCalculator(), $translator, $leagueName), t: $translator);
$cmsView    = new CmsView($translator);
// Player page, public and in the admin area (R39).
$playerProfiles    = new \Bowlingliga\Application\PlayerProfile\PlayerProfiles(new \Bowlingliga\Persistence\PlayerProfile\PlayerProfileRepository($database));
$playerProfileView = new \Bowlingliga\Presentation\PlayerProfileView($translator);
// Team page, public and in the admin area (R41).
$teamProfiles    = new \Bowlingliga\Application\TeamProfile\TeamProfiles(
    new \Bowlingliga\Persistence\TeamProfile\TeamProfileRepository($database),
    new TableRawDataRepository($database),
    new TableAggregator(new TableRawDataRepository($database)),
);
$teamProfileView = new \Bowlingliga\Presentation\TeamProfileView($translator);
// Text of the catalog in the request's language (for page titles and short messages).
$t = static fn (string $key, array $params = []): string => $translator->t($key, $params);

$router = new Router();

/**
 * All seasons, read once per request (ascending by start date).
 *
 * @return list<array<string, mixed>>
 */
$allSeasons = static function () use ($seasons): array {
    static $all = null;

    return $all ??= $seasons->findAllSeasons();
};

/**
 * The current season without a choice: the running one, in the summer break
 * the one that ended last (R28.18, {@see CurrentSeason}); null without seasons.
 */
$defaultSeasonId = static fn (): ?int => CurrentSeason::of($allSeasons(), new DateTimeImmutable());

/** Season chosen via the query parameter `saison`, or null. */
$chosenSeasonId = static function (): ?int {
    $parameter = filter_input(INPUT_GET, 'season', FILTER_VALIDATE_INT);

    return is_int($parameter) && $parameter > 0 ? $parameter : null;
};

/**
 * Determines the season to show on public pages: the chosen one, otherwise
 * the current one; null if no season exists.
 */
$currentSeasonId = static fn (): ?int => $chosenSeasonId() ?? $defaultSeasonId();

/**
 * Season the navigation links carry: only another season than the current
 * one, so that links and bookmarks without a choice follow the current
 * season.
 */
$seasonForLinks = static function (?int $seasonId) use ($defaultSeasonId): ?int {
    return $seasonId !== null && $seasonId !== $defaultSeasonId() ? $seasonId : null;
};

/**
 * Renders a complete public page with navigation that keeps the selected
 * season (R28.2): the navigation items carry the query parameter `saison`.
 * Pages of a matchday or team pass their own season.
 */
$page = static function (string $title, string $content, ?int $seasonId = null) use ($layout, $navigation, $currentSeasonId, $seasonForLinks): string {
    return $layout->render(
        $title,
        $content,
        $navigation->entries($seasonForLinks($seasonId ?? $currentSeasonId())),
        (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    );
};

// --- Public (reading) routes ------------------------------------------------

// Home page: last matchday, league table, posts and season selection (R33.7–R33.9).
$router->public('/', static function () use ($page, $controller, $cmsView, $homePage, $seasons, $currentSeasonId, $leagueName): void {
    $content = $controller->homePage(
        $currentSeasonId(),
        $cmsView->renderHomePagePosts($homePage->findAllContents()),
        $seasons->findAllSeasons(),
    );

    echo $page($leagueName, $content);
});

// Choose the appearance (style and colour mode). Without JavaScript the
// toggle in the footer submits this form; the choice ends up in two cookies
// (display values only, no personal data), then the user returns
// to the page. No CSRF protection is needed, since only the own
// appearance is affected.
$router->public('/appearance', static function (): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $appearance = Appearance::from($_POST['style'] ?? null, $_POST['mode'] ?? null);
        $optionList = [
            'expires'  => time() + Appearance::COOKIE_LIFETIME_SECONDS,
            'path'     => '/',
            'samesite' => 'Lax',
        ];
        setcookie(Appearance::COOKIE_STYLE, $appearance->style, $optionList);
        setcookie(Appearance::COOKIE_MODE, $appearance->mode, $optionList);
        if (\Bowlingliga\I18n\Languages::isSupported($_POST['language'] ?? null)) {
            setcookie(\Bowlingliga\I18n\LanguageNegotiator::COOKIE, $_POST['language'], $optionList);
        }
    }

    // Only allow local return targets (no open redirect).
    header('Location: ' . Appearance::returnTarget($_POST['return_to'] ?? null));
    http_response_code(303);
});

// Matchday list of the current or selected season (R14).
$router->public('/matchdays', static function () use ($t, $page, $controller, $currentSeasonId): void {
    $seasonId = $currentSeasonId();
    if ($seasonId === null) {
        echo $page($t('nav.matchdays'), '<h1>' . htmlspecialchars($t('nav.matchdays')) . '</h1><p>' . htmlspecialchars($t('public.no_seasons')) . '</p>');

        return;
    }

    echo $page($t('nav.matchdays'), $controller->matchdayList($seasonId));
});

// Detail view of a matchday (R15); the navigation keeps the matchday's season.
$router->publicWithId('/matchday', static function (int $matchdayId) use ($t, $page, $controller, $matchdays, $playersVisible): void {
    $matchday = $matchdays->find($matchdayId);

    echo $page($t('public.matchday'), $controller->matchdayDetail($matchdayId, $playersVisible()), $matchday !== null ? (int) $matchday['season_id'] : null);
});

// Team page with statistics and the current average list (R41, R2), linked from
// the league table. The navigation keeps the team's season.
$router->publicWithId('/team', static function (int $teamSeasonId) use ($t, $page, $controller, $teamProfiles, $teamProfileView, $playersVisible): void {
    $profile = $teamProfiles->profile($teamSeasonId);
    if ($profile === null) {
        http_response_code(404);
        echo $page($t('team.title'), '<p class="notice" role="status">' . htmlspecialchars($t('team.not_found')) . '</p>');

        return;
    }

    echo $page(
        $profile->teamName,
        $teamProfileView->render(
            $profile,
            static fn (int $id): string => '/team/' . $id,
            $controller->teamAverageList($teamSeasonId, $playersVisible(), false),
            '<p><a class="more" href="/table?season=' . $profile->seasonId . '">' . htmlspecialchars($t('public.back_to_table')) . '</a></p>',
        ),
        $profile->seasonId,
    );
});

// Player page with handicap and average history and teams (R39), linked from
// the average lists; the season comes from `?season=`, otherwise the current one.
$router->publicWithId('/player', static function (int $playerId) use ($t, $page, $playerProfiles, $playerProfileView, $chosenSeasonId, $defaultSeasonId, $playersVisible): void {
    // R40.2: without public player data there is no public player page.
    if (! $playersVisible()) {
        http_response_code(404);
        echo $page($t('player.title'), '<p class="notice" role="status">' . htmlspecialchars($t('public.players_not_public')) . '</p>');

        return;
    }

    $profile = $playerProfiles->profile($playerId, $chosenSeasonId(), $defaultSeasonId());
    if ($profile === null) {
        http_response_code(404);
        echo $page($t('player.title'), '<p class="notice" role="status">' . htmlspecialchars($t('player.not_found')) . '</p>');

        return;
    }

    echo $page(
        $profile->name,
        $playerProfileView->render($profile, static fn (int $seasonId): string => '/player/' . $playerId . '?season=' . $seasonId),
        $profile->selectedSeasonId,
    );
});

// League table of the current or selected season (R16).
$router->public('/table', static function () use ($t, $page, $controller, $currentSeasonId): void {
    $seasonId = $currentSeasonId();
    if ($seasonId === null) {
        echo $page($t('nav.table'), '<h1>' . htmlspecialchars($t('public.league_table')) . '</h1><p>' . htmlspecialchars($t('public.no_seasons')) . '</p>');

        return;
    }

    echo $page($t('nav.table'), $controller->leagueTable($seasonId));
});

// Honours of the current or selected season (R13).
$router->public('/honours', static function () use ($t, $page, $controller, $currentSeasonId, $playersVisible): void {
    $seasonId = $currentSeasonId();
    if ($seasonId === null) {
        echo $page($t('nav.honours'), '<h1>' . htmlspecialchars($t('nav.honours')) . '</h1><p>' . htmlspecialchars($t('public.no_seasons')) . '</p>');

        return;
    }

    echo $page($t('nav.honours'), $controller->honours($seasonId, $playersVisible()));
});

// Spielregeln-Seite (R34.2, R34.6) with the handicap table of the current
// season as a second tab.
$router->public('/rules', static function () use ($t, $page, $cmsView, $rules, $seasons, $currentSeasonId, $database): void {
    $handicapTable = null;
    $seasonName = '';
    $seasonId = $currentSeasonId();
    $config = $seasonId !== null ? (new SeasonConfigRepository($database))->find($seasonId) : null;
    $formula = $config !== null ? HandicapFreezingRepository::readFormula($config['handicap_formula']) : null;
    if ($config !== null && $formula !== null) {
        $handicapTable = (new HandicapTableService())->build($formula, (int) $config['target_score'], (int) $config['max_handicap']);
        foreach ($seasons->findAllSeasons() as $season) {
            if ((int) $season['id'] === $seasonId) {
                $seasonName = (string) $season['name'];
            }
        }
    }

    echo $page($t('nav.rules'), $cmsView->renderRules($rules->findText(), $handicapTable, $seasonName));
});

// Delivery of the uploaded original score sheet (R3.16, R15.4).
// Public only once the fixture is entered completely (before that only for
// logged-in admins); otherwise 404, without revealing that it exists.
$router->publicWithId('/score-sheet', static function (int $id) use ($t, $scoreSheet, $teamFixtures, $auth, $playersVisible): void {
    $dataRecord = $scoreSheet->find($id);
    $fixture   = $dataRecord !== null ? $teamFixtures->find((int) $dataRecord['fixture_id']) : null;
    // The original shows the players' names: without public player data only for admins (R40.2).
    $visible  = $fixture !== null && (($fixture['completion_status'] === 'complete' && $playersVisible()) || $auth->checkWriteAccess());

    if ($dataRecord === null || ! $visible) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo $t('public.score_sheet_not_found');

        return;
    }

    // The record exists but its file is missing from the score sheet directory:
    // raw data is lost, which must not look like "not found" (R35).
    if ($dataRecord['file'] === null) {
        error_log("Original score sheet {$id}: file {$dataRecord['content_hash']} is missing in the score sheet directory.");
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $t('public.score_sheet_file_missing');

        return;
    }

    // The content never changes for a hash, so it serves as ETag.
    $etag = '"' . $dataRecord['content_hash'] . '"';
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);

        return;
    }

    // Show the image/PDF directly; the page CSP would block the embedded PDF viewers
    // of some browsers.
    header_remove('Content-Security-Policy');
    header('Content-Type: ' . (string) $dataRecord['mime_type']);
    header('Content-Disposition: inline');
    // Visibility depends on the fixture status, so the browser asks again each time.
    header('ETag: ' . $etag);
    header('Cache-Control: private, no-cache');
    echo $dataRecord['file'];
});

// --- Composition root: master data management (task 35.1) -----------------
// The writing use cases (tasks 21.1–21.4) are bound to real
// repositories; write access is checked via the auth module
// (R5.1), the inputs are validated via the validator (task 19).
$validator        = new \Bowlingliga\Security\Validator();
$playerRepo      = new PlayerRepository($database);
$rosterRepo        = new RosterRepository($database);
$teamRepo         = new TeamRepository($database);
$seasonConfigRepo = new SeasonConfigRepository($database);

$playerManagement  = new PlayerManagement($playerRepo, $rosterRepo, $validator, $auth);
$teamManagement     = new TeamManagement($teamRepo, $teamSeasons, $seasons, $validator, $auth);
$seasonAdministration   = new SeasonAdministration(
    $seasons,
    $seasonConfigRepo,
    $teamSeasons,
    $teamRepo,
    $validator,
    $auth,
    rosterReader: $rosterRepo,
    rosterStore: $rosterRepo,
    scoreSheetFiles: $scoreSheet,
);
$matchdayManagement = new MatchdayManagement($matchdays, $seasons, $auth);
$accountManagement    = new AdminAccountManagement(
    new AccountManagement($adminAccounts, new PasswordHasher(), new TotpSecretEncryption($totpKey), issuer: $leagueName),
    $auth,
    $auth,
);

$masterDataReader = new \Bowlingliga\Persistence\MasterData\RepositoryMasterDataReader(
    $playerRepo,
    $teamSeasons,
    $seasons,
    $matchdays,
    $adminAccounts,
    $rosterRepo,
);
$masterDataController = new MasterDataController(
    $playerManagement,
    $teamManagement,
    $seasonAdministration,
    $matchdayManagement,
    $accountManagement,
    $masterDataReader,
    new MasterDataView($translator),
    t: $translator,
);

// Player import (master data tab "Import").
$previousSeasonContext = new PreviousSeasonContextRepository($database);
$playerImportController = new PlayerImportController(
    new PlayerImport(
        // Column names and gender values of all languages (R6).
        new PlayerCsvParser(\Bowlingliga\I18n\CatalogImportVocabulary::all()),
        new PlayerImportRepository($database),
        $previousSeasonContext,
        $playerManagement,
        $teamManagement,
        new PreviousSeasonRecordManagement(new PreviousSeasonRecordRepository($database), $previousSeasonContext, $validator, $auth),
        new \Bowlingliga\Persistence\PdoTransaction($database),
        $validator,
        $auth,
    ),
    $masterDataReader,
    new PlayerImportView($translator),
    t: $translator,
);

// --- Composition root: printed score sheet (task 35.4) ---------------------
// Binds the pure printed score sheet service (task 30) via the
// data adapter to real raw data and generates a PDF (mPDF) or
// the print-optimised HTML view as needed (R19.4).
$printController = new PrintController(
    new PrintScoreSheetData(
        $teamFixtures,
        $matchdays,
        $teamSeasons,
        $seasonConfigRepo,
        new LanePairRepository($database),
        $rosterRepo,
        $playerRepo,
        new AverageListAggregator(new AverageListRawDataRepository($database)),
        new \Bowlingliga\Persistence\FrozenHandicapRepository($database),
        new HandicapFreezingRepository($database, new \Bowlingliga\Persistence\FrozenHandicapRepository($database)),
        seasons: $seasons,
    ),
    new PrintScoreSheetService(leagueName: $leagueName),
    new MpdfPrintScoreSheetRenderer(),
    // QR code per team for the result entry by the teams (R37.2); the team
    // entry is wired further down, hence the reference.
    static function (int $fixtureId, string $side) use (&$teamResultEntry, &$baseUrl): ?string {
        $code = $teamResultEntry?->codeFor($fixtureId, $side);

        return $code !== null
            ? (new \Bowlingliga\Presentation\QrCode())->svgDataUri(\Bowlingliga\Presentation\TeamEntryController::entryUrl($baseUrl, $code))
            : null;
    },
    t: $translator,
    defaultLanguage: $defaultLanguage,
);

// --- Composition root: CMS editing (task 35.6) ------------------------------
// Binds the CMS use cases (task 29.1/29.2) to the real repositories;
// validation (task 19), HTML sanitizing (R33.11, R34.8) and write protection
// (R5.1) live in the use cases.
$htmlSanitizer = new HtmlSanitizer();
$cmsEditController = new CmsEditController(
    new HomePagePostManagement($homePage, $validator, $htmlSanitizer, $auth),
    new RulesManagement($rules, $validator, $htmlSanitizer, $auth),
    new RepositoryCmsReader($homePage, $rules),
    new CmsEditView($translator),
    t: $translator,
);

// --- Composition root: league settings (R38) --------------------------------
$leagueSettingsController = new LeagueSettingsController(
    new LeagueSettings($leagueStore, $validator, $auth),
    new LeagueSettingsView($translator),
    t: $translator,
);

// --- Composition root: season planning and lane assignment (task 35.2) -----
// Binds the use case (tasks 15, 16, 23) to real repositories; the
// matchday correction before season start uses the matchday management (R9.14).
$seasonPlanRepo          = new SeasonPlanRepository($database);
$seasonPlanningData      = new SeasonPlanningDataRepository($database, $seasonPlanRepo);
$seasonPlanningController = new SeasonPlanningController(
    new SeasonPlanningManagement(
        $seasonPlanningData,
        $seasonPlanRepo,
        new SeasonPlanner(),
        new LaneAssignmentService(),
        $auth,
    ),
    $matchdayManagement,
    $seasonPlanningData,
    new SeasonPlanningView($translator),
    t: $translator,
);

// --- Composition root: season (master data, configuration, positiondays) ---
// Editing the season and forming the positionday fixtures from the
// standings (R8.5, R12.7, R31).
$positiondayContext = new \Bowlingliga\Persistence\Positionday\PositiondayContextRepository(
    $database,
    new TableAggregator(new TableRawDataRepository($database)),
);
$seasonManagementController = new \Bowlingliga\Presentation\SeasonManagementController(
    $seasonAdministration,
    new \Bowlingliga\Application\Positionday\PositiondayManagement(
        $positiondayContext,
        new \Bowlingliga\Persistence\PositiondayFixtureRepository($database),
        new \Bowlingliga\Domain\Positionday\PositiondayService(),
        new LaneAssignmentService(),
        $auth,
        $scoreSheet,
    ),
    new \Bowlingliga\Application\LotDraw\LotDrawManagement(
        new \Bowlingliga\Persistence\LotDraw\LotDrawRepository($database),
        new \Bowlingliga\Persistence\PositiondayFixtureRepository($database),
        $auth,
    ),
    new \Bowlingliga\Persistence\SeasonManagement\SeasonManagementDataRepository($database, $seasons, $seasonConfigRepo, $positiondayContext),
    new \Bowlingliga\Presentation\SeasonManagementView($translator),
    $validator,
    t: $translator,
    defaultLanguage: $defaultLanguage,
    // Cancel or postpone a whole matchday (R8.20, R8.21).
    rescheduling: new \Bowlingliga\Application\Schedule\MatchdayRescheduling(
        new \Bowlingliga\Persistence\Schedule\MatchdayScheduleRepository($database),
        $auth,
        new \Bowlingliga\Persistence\PdoTransaction($database),
    ),
);

// --- Composition root: honours preview (task 35.5) --------------------------
// The same calculation as in public, but visible to logged-in administrators
// before the end date has passed (R13.9, R28.14).
$honoursPreviewController = new HonoursPreviewController(
    new HonoursAggregator(new HonoursRawDataRepository($database)),
    $seasons,
    new PublicView(new \Bowlingliga\Presentation\ScoreSheetCalculator(), $translator, $leagueName),
    $auth,
    t: $translator,
);

// --- Composition root: result entry (task 35.3) ----------------------------
// Binds overview, virtual score sheet, result saving (including the lineup
// rows, cache invalidation and handicap freezing) and the upload of the
// original score sheet to real repositories (tasks 22, 24, 25). OCR is
// deferred (handwritten values cannot be recognized). The separate lineup
// step (LineupManagement) is deactivated: the lineup follows from the
// results, since players can be substituted between games.
$handicapData   = new HandicapFreezingRepository($database, new \Bowlingliga\Persistence\FrozenHandicapRepository($database));
// History of a fixture (team entry and admin changes), only visible to admins.
// The admin's entries carry the username of the logged-in account.
$adminName = static function () use ($auth, $adminAccounts): ?string {
    $accountId = $auth->loggedInAccount();
    $account   = $accountId !== null ? $adminAccounts->find($accountId) : null;

    return $account !== null ? (string) $account['username'] : null;
};
$fixtureLog = new \Bowlingliga\Persistence\ResultEntry\FixtureLogRepository($database, $adminName);

// The same result recording serves the admin (login, changes go to the history)
// and the final saving of results confirmed by both teams (TeamEntryWriteAccess,
// R37.6; the teams log their entries themselves).
$newResultRecording = static fn (\Bowlingliga\Security\WriteAccess $access, ?\Bowlingliga\Application\ResultEntry\ResultLog $log = null): ResultRecording => new ResultRecording(
    new ResultRepository($database),
    $teamFixtures,
    $matchdays,
    $matchdays,
    $handicapData,
    new CacheManager(new CacheRepository($database)),
    $validator,
    $access,
    $rosterRepo,
    $handicapData,
    new \Bowlingliga\Persistence\PdoTransaction($database),
    $log,
);
$resultRecording = $newResultRecording($auth, $fixtureLog);
$resultEntryController = new ResultEntryController(
    new ScoreSheetOverview(new OverviewRepository($database)),
    new VirtualScoreSheetService(new ScoreSheetRepository($database)),
    $resultRecording,
    new BlindPlayerOrder($rosterRepo, $handicapData),
    new OriginalScoreSheetStorage($scoreSheet, $auth),
    new EntryDataRepository($database),
    new ResultEntryView($translator),
    $handicapData,
    t: $translator,
);

// --- Composition root: result entry by the teams (R37) ----------------------
// Each team enters its own lineup and results game by game with the code from
// the QR code on the printed score sheet, the opponent confirms them; codes are
// derived from the TOTP key. The address in the QR code comes from `base_url`
// in the configuration, otherwise from the current request.
$teamEntryAccess = new \Bowlingliga\Application\TeamEntry\TeamEntryWriteAccess();
$teamResultEntry = new \Bowlingliga\Application\TeamEntry\TeamResultEntry(
    new \Bowlingliga\Persistence\TeamEntry\TeamEntryRepository($database, $adminName),
    new \Bowlingliga\Security\TeamEntryCodes($totpKey),
    $newResultRecording($teamEntryAccess),
    $teamEntryAccess,
    $resultRecording,
    $auth,
    new BlindPlayerOrder($rosterRepo, $handicapData),
    new \Bowlingliga\Application\ResultEntry\BlindFilling(new BlindPlayerOrder($rosterRepo, $handicapData), $handicapData),
    new \Bowlingliga\Persistence\PdoTransaction($database),
);
$teamEntryController = new \Bowlingliga\Presentation\TeamEntryController($teamResultEntry, $handicapData, new \Bowlingliga\Presentation\TeamEntryView($translator), t: $translator);
$fixtureHistory = new \Bowlingliga\Presentation\FixtureHistoryController(
    new \Bowlingliga\Application\ResultEntry\FixtureHistory($fixtureLog, $auth),
    new EntryDataRepository($database),
    new \Bowlingliga\Presentation\FixtureHistoryView($translator),
);
$baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
if ($baseUrl === '') {
    $baseUrl = (TransportSecurity::isHttps($_SERVER) ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * Renders a complete admin area page with the public navigation (the season
 * selection is kept in the admin area as well).
 */
/**
 * Season the admin works with (R28.18): chosen via `?season=` (selection below
 * the admin bar, links of single areas) and kept in the admin session, so it
 * survives forms, redirects and links without the parameter; otherwise the
 * current season. A deleted season no longer counts.
 */
$adminSeasonId = static function () use ($session, $allSeasons, $chosenSeasonId, $defaultSeasonId): ?int {
    $known = array_map(static fn (array $season): int => (int) $season['id'], $allSeasons());

    $chosen = $chosenSeasonId();
    if ($chosen !== null && in_array($chosen, $known, true)) {
        $session->set(AdminView::SESSION_SEASON, $chosen);

        return $chosen;
    }

    $kept = $session->retrieve(AdminView::SESSION_SEASON);

    return is_int($kept) && in_array($kept, $known, true) ? $kept : $defaultSeasonId();
};

$adminPage = static function (string $title, string $content) use ($translator, $layout, $navigation, $adminSeasonId, $defaultSeasonId, $seasonForLinks, $allSeasons, $auth, $adminView): string {
    // Admin bar and season selection only with an existing login (not on the login page).
    $loggedIn = $auth->checkWriteAccess();

    return $layout->render(
        $translator->t($title),
        $content,
        $navigation->entries($seasonForLinks($adminSeasonId())),
        (string) ($_SERVER['REQUEST_URI'] ?? '/'),
        $loggedIn ? $adminView->barEntries() : [],
        $loggedIn ? ['seasons' => $allSeasons(), 'selected' => $adminSeasonId(), 'current' => $defaultSeasonId()] : null,
    );
};

// --- Administrative Routen -----------------------------------------------

// Login form as entry to the admin area (R28.8).
$router->admin('/login', static function () use ($t, $translator, $adminPage, $adminView, $csrf, $auth): void {
    // Already logged in: go straight to the admin area overview.
    if ($auth->checkWriteAccess()) {
        header('Location: /admin');
        http_response_code(302);

        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method !== 'POST') {
        echo $adminPage('admin.login', $adminView->renderLogin($csrf->fieldHtml()));

        return;
    }

    // POST: check the CSRF token (R22.5); on failure show the form again.
    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $adminPage(
            'admin.login',
            $adminView->renderLogin($csrf->fieldHtml(), $t('common.session_expired_login'))
        );

        return;
    }

    // Step 1: username and password. With active 2FA the code follows as
    // a separate step (R23.7).
    $username = (string) ($_POST['username'] ?? '');
    $password     = (string) ($_POST['password'] ?? '');

    $result = $auth->login($username, $password);

    if ($result->isSuccessful()) {
        header('Location: /admin');
        http_response_code(302);

        return;
    }

    if ($result->status === LoginStatus::SECOND_FACTOR_REQUIRED) {
        header('Location: /admin/login/code');
        http_response_code(303);

        return;
    }

    // Fehlgeschlagene Anmeldung: generische/zielgerichtete Meldung erneut
    // anzeigen (R5.3, R23.8, R5.6).
    http_response_code(401);
    echo $adminPage('admin.login', $adminView->renderLogin($csrf->fieldHtml(), $result->message !== null ? $translator->message($result->message) : null));
});

// Step 2 of the login: code from the authenticator app (R23.7, R23.8). Only
// reachable while the time limit after the successful password check runs.
$router->admin('/login/code', static function () use ($t, $translator, $adminPage, $adminView, $csrf, $auth): void {
    if ($auth->checkWriteAccess()) {
        header('Location: /admin');
        http_response_code(302);

        return;
    }

    if (! $auth->awaitsSecondFactor()) {
        header('Location: /admin/login');
        http_response_code(302);

        return;
    }

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        echo $adminPage('admin.login', $adminView->renderSecondFactor($csrf->fieldHtml()));

        return;
    }

    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $adminPage('admin.login', $adminView->renderSecondFactor($csrf->fieldHtml(), $t('common.session_expired_retry')));

        return;
    }

    if (($_POST['cancel'] ?? '') === '1') {
        $auth->cancelSecondFactor();
        header('Location: /admin/login');
        http_response_code(303);

        return;
    }

    $result = $auth->confirmSecondFactor(trim((string) ($_POST['totp'] ?? '')));

    if ($result->isSuccessful()) {
        header('Location: /admin');
        http_response_code(302);

        return;
    }

    http_response_code(401);
    // Wrong code: try again; locked or expired: start over.
    echo $adminPage('admin.login', $result->status === LoginStatus::INVALID_SECOND_FACTOR
        ? $adminView->renderSecondFactor($csrf->fieldHtml(), $result->message !== null ? $translator->message($result->message) : null)
        : $adminView->renderLogin($csrf->fieldHtml(), $result->message !== null ? $translator->message($result->message) : null));
});

// Logout (R5.5): only via POST, then back to the login form.
$router->admin('/logout', static function () use ($auth): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $auth->logout();
    }

    header('Location: /admin/login');
    http_response_code(302);
});

// Admin area overview after a successful login (R28.10).
// Without a valid session access is denied and the user is sent to the login
// (R28.9, R5.1).
$router->admin('/', static function () use ($adminPage, $adminView, $auth, $csrf): void {
    if (! $auth->checkWriteAccess()) {
        // R28.9: deny access and ask the user to log in.
        http_response_code(401);
        echo $adminPage(
            'admin.login',
            $adminView->renderLogin($csrf->fieldHtml(), null, true)
        );

        return;
    }

    echo $adminPage('layout.admin_area', $adminView->renderOverview());
});

// --- Stammdatenverwaltung (Task 35.1) ------------------------------------

/**
 * Ensures that valid write access exists; otherwise access is denied and the
 * user is asked to log in (R28.9, R5.1).
 *
 * @return bool true if access is allowed; false if the response already asked
 *              the user to log in.
 */
$ensureAdminAccess = static function () use ($auth, $adminPage, $adminView, $csrf): bool {
    if ($auth->checkWriteAccess()) {
        return true;
    }

    http_response_code(401);
    echo $adminPage('admin.login', $adminView->renderLogin($csrf->fieldHtml(), null, true));

    return false;
};

// Master data overview (R28.10): lists and create forms.
$router->admin('/master-data', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $masterDataController,
    $playerImportController,
    $csrf,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $tab = filter_input(INPUT_GET, 'tab');
    $tab = is_string($tab) ? $tab : 'players';
    $importArea = $tab === 'import' ? $playerImportController->form($csrf->fieldHtml(), $adminSeasonId()) : '';

    echo $adminPage(
        'admin.area.master_data.title',
        $masterDataController->overview($csrf->fieldHtml(), $adminSeasonId(), null, null, $tab, $importArea)
    );
});

// Player page in the admin area (R39), linked from the player list and the
// rosters; same content as the public page.
$router->admin('/master-data/player', static function () use ($t, $ensureAdminAccess, $adminPage, $playerProfiles, $playerProfileView, $chosenSeasonId, $adminSeasonId): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $playerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $profile  = is_int($playerId) && $playerId > 0 ? $playerProfiles->profile($playerId, $chosenSeasonId(), $adminSeasonId()) : null;
    $back     = '<p><a class="more" href="/admin/master-data">' . htmlspecialchars($t('player.back_to_list')) . '</a></p>';
    if ($profile === null) {
        http_response_code(404);
        echo $adminPage('player.title', '<p class="notice" role="status">' . htmlspecialchars($t('player.not_found')) . '</p>' . $back);

        return;
    }

    echo $adminPage(
        'player.title',
        $playerProfileView->render($profile, static fn (int $seasonId): string => '/admin/master-data/player?id=' . $profile->playerId . '&season=' . $seasonId, $back),
    );
});

// Team page in the admin area, linked from the master data (R41.8); player
// data is always visible here.
$router->admin('/master-data/team', static function () use ($t, $ensureAdminAccess, $adminPage, $teamProfiles, $teamProfileView, $controller): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $teamSeasonId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $profile      = is_int($teamSeasonId) && $teamSeasonId > 0 ? $teamProfiles->profile($teamSeasonId) : null;
    $back         = '<p><a class="more" href="/admin/master-data?tab=teams">' . htmlspecialchars($t('team.back_to_list')) . '</a></p>';
    if ($profile === null) {
        http_response_code(404);
        echo $adminPage('team.title', '<p class="notice" role="status">' . htmlspecialchars($t('team.not_found')) . '</p>' . $back);

        return;
    }

    echo $adminPage(
        'team.title',
        $teamProfileView->render(
            $profile,
            static fn (int $id): string => '/admin/master-data/team?id=' . $id,
            $controller->teamAverageList($profile->teamSeasonId, true, false),
            str_replace('?tab=teams', '?tab=teams&amp;season=' . $profile->seasonId, $back),
        ),
    );
});

// Template of the import file in the visitor's language (R6).
$router->admin('/master-data/import-template', static function () use ($ensureAdminAccess, $playerImportController): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $template = $playerImportController->template();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $template['file']) . '"');
    echo $template['content'];
});

// Player import: preview of the uploaded file (nothing is saved yet), then the
// import of the confirmed file. Both check access and CSRF token first.
$router->admin('/master-data/import-preview', static function () use (
    $t,
    $ensureAdminAccess,
    $adminPage,
    $masterDataController,
    $playerImportController,
    $csrf,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }
    $seasonId = $adminSeasonId();
    $page = static fn (string $area, ?string $error = null): string => $adminPage(
        'admin.area.master_data.title',
        $masterDataController->overview($csrf->fieldHtml(), $seasonId, null, $error, 'import', $area)
    );

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        echo $page($playerImportController->form($csrf->fieldHtml(), $seasonId));

        return;
    }
    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $page(
            $playerImportController->form($csrf->fieldHtml(), $seasonId),
            $t('common.session_expired')
        );

        return;
    }

    $file = $_FILES['file'] ?? null;
    $content = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name'])
        ? (string) file_get_contents((string) $file['tmp_name'])
        : null;

    echo $page($playerImportController->preview($csrf->fieldHtml(), $_POST, $content));
});

$router->admin('/master-data/import', static function () use (
    $t,
    $ensureAdminAccess,
    $adminPage,
    $masterDataController,
    $playerImportController,
    $csrf,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }
    $seasonId = $adminSeasonId();
    $formPage = static fn (?string $error): string => $adminPage('admin.area.master_data.title', $masterDataController->overview(
        $csrf->fieldHtml(),
        $seasonId,
        null,
        $error,
        'import',
        $playerImportController->form($csrf->fieldHtml(), $seasonId),
    ));

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        echo $formPage(null);

        return;
    }
    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $formPage($t('common.session_expired_upload'));

        return;
    }

    $result = $playerImportController->import($_POST);
    $importedSeason = (int) ($_POST['season_id'] ?? 0);

    // After a successful import the teams of the season are shown.
    echo $result->successful
        ? $adminPage('admin.area.master_data.title', $masterDataController->overview($csrf->fieldHtml(), $importedSeason, $result->message, null, 'teams'))
        : $formPage($result->message);
});

// Writing master data actions (POST): one route per action under
// /admin/master-data/{aktion}. All of them check write access (R5.1) and the CSRF token
// (R22.5) before the use case runs; then the overview is
// rendered again with a success/error message.
$masterDataActions = [
    'player-create', 'player-remove',
    'season-create', 'season-remove',
    'team-create', 'team-remove',
    'matchday-create',
    'account-create', 'account-remove',
    'roster-assign', 'roster-remove',
];

foreach ($masterDataActions as $action) {
    $router->admin('/master-data/' . $action, static function () use (
        $t,
        $action,
        $ensureAdminAccess,
        $adminPage,
        $masterDataController,
        $csrf,
        $adminSeasonId
    ): void {
        if (! $ensureAdminAccess()) {
            return;
        }

        $seasonId = $adminSeasonId();
        // After the action its tab stays selected.
        $tab = MasterDataController::tabForAction($action);

        // Only run on POST; other methods lead back to the overview.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            echo $adminPage('admin.area.master_data.title', $masterDataController->overview($csrf->fieldHtml(), $seasonId, null, null, $tab));

            return;
        }

        // R22.5: check the CSRF token before a writing action runs.
        if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo $adminPage(
                'admin.area.master_data.title',
                $masterDataController->overview(
                    $csrf->fieldHtml(),
                    $seasonId,
                    null,
                    $t('common.session_expired'),
                    $tab,
                )
            );

            return;
        }

        $result = $masterDataController->process($action, $_POST);

        echo $adminPage(
            'admin.area.master_data.title',
            $masterDataController->overview(
                $csrf->fieldHtml(),
                $seasonId,
                $result->successful ? $result->message : null,
                $result->successful ? null : $result->message,
                $tab,
            )
        );
    });
}

// --- Printed score sheet (task 35.4) ----------------------------------------
// Admin area for printing the score sheets (R28.13, R19). A matchday
// is selected (`?matchday=`); the result is one document with all
// fixtures, one per page. Without a parameter the selection appears with the
// next open matchday preselected. `?fixture=` still prints
// a single fixture.
$router->admin('/print', static function () use (
    $t,
    $ensureAdminAccess,
    $adminPage,
    $printController,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $matchdayId    = filter_input(INPUT_GET, 'matchday', FILTER_VALIDATE_INT);
    $fixtureId = filter_input(INPUT_GET, 'fixture', FILTER_VALIDATE_INT);

    if (is_int($matchdayId) && $matchdayId > 0) {
        $result = $printController->generateForMatchday($matchdayId, is_string($_GET['language'] ?? null) ? $_GET['language'] : null);
        $fileName = $t('print.file_matchday');
    } elseif (is_int($fixtureId) && $fixtureId > 0) {
        $result = $printController->generate($fixtureId, is_string($_GET['language'] ?? null) ? $_GET['language'] : null);
        $fileName = $t('print.file_fixture');
    } else {
        echo $adminPage('print.title', $printController->selection($adminSeasonId()));

        return;
    }

    if ($result->isError()) {
        // R19.5: e.g. missing lane assignment – selection with a note.
        http_response_code(422);
        echo $adminPage('print.title', $printController->selection($adminSeasonId(), $result->content));

        return;
    }

    // Deliver the PDF or the HTML fallback directly (R19.4).
    header('Content-Type: ' . $result->mimeType);
    if ($result->type === \Bowlingliga\Presentation\PrintScoreSheetResult::TYPE_PDF) {
        // The page CSP would block the PDF viewer of some browsers.
        header_remove('Content-Security-Policy');
        header('Content-Disposition: inline; filename="' . $fileName . '"');
    }
    echo $result->content;
});

// --- CMS editing (task 35.6) ------------------------------------------------
// Home page posts (R28.16) and rules (R28.17). All routes check
// write access (R5.1, R28.9); writing actions only via POST with a
// valid CSRF token (R22.5).

$isPost = static fn (): bool => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';

$csrfExpired = $t('common.session_expired');

// "Mein Konto": set up/disable the two-factor login, change the password
// (R23.3, R23.6–R23.9). Always refers to the logged-in account.
$accountController = new AccountController($accountManagement, $session, new AccountView($translator), t: $translator);

$router->admin('/account', static function () use ($ensureAdminAccess, $adminPage, $accountController, $csrf, $auth): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    echo $adminPage('layout.my_account', $accountController->page($csrf->fieldHtml(), (int) $auth->loggedInAccount()));
});

$accountActions = [
    'totp-start'      => static fn (int $account, array $input) => $accountController->startTotp($account),
    'totp-activate'   => static fn (int $account, array $input) => $accountController->activateTotp($account, $input),
    'totp-cancel'    => static fn (int $account, array $input) => $accountController->cancelTotp(),
    'totp-deactivate' => static fn (int $account, array $input) => $accountController->deactivateTotp($account, $input),
    'password'          => static fn (int $account, array $input) => $accountController->changePassword($account, $input),
    'end-sessions' => static fn (int $account, array $input) => $accountController->endOtherSessions($account),
];

foreach ($accountActions as $pathName => $action) {
    $router->admin('/account/' . $pathName, static function () use (
        $action,
        $isPost,
        $csrfExpired,
        $ensureAdminAccess,
        $adminPage,
        $accountController,
        $csrf,
        $auth
    ): void {
        if (! $ensureAdminAccess()) {
            return;
        }

        if (! $isPost()) {
            header('Location: /admin/account');
            http_response_code(302);

            return;
        }

        $account = (int) $auth->loggedInAccount();

        if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo $adminPage('layout.my_account', $accountController->page($csrf->fieldHtml(), $account, null, $csrfExpired));

            return;
        }

        $result = $action($account, $_POST);
        if (! $result->successful) {
            http_response_code(422);
        }

        echo $adminPage('layout.my_account', $accountController->page(
            $csrf->fieldHtml(),
            $account,
            $result->successful ? $result->message : null,
            $result->successful ? null : $result->message,
        ));
    });
}

// Editing the home page posts; `?edit={id}` prefills the form.
$router->admin('/home-page', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $cmsEditController,
    $csrf
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $edit = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    echo $adminPage(
        'cms.home_page_contents',
        $cmsEditController->homePageEdit(
            $csrf->fieldHtml(),
            is_int($edit) && $edit > 0 ? $edit : null,
        )
    );
});

foreach (CmsEditController::HOME_PAGE_ACTIONS as $action) {
    $router->admin('/home-page/' . $action, static function () use (
        $action,
        $isPost,
        $csrfExpired,
        $ensureAdminAccess,
        $adminPage,
        $cmsEditController,
        $csrf
    ): void {
        if (! $ensureAdminAccess()) {
            return;
        }

        if (! $isPost()) {
            echo $adminPage('cms.home_page_contents', $cmsEditController->homePageEdit($csrf->fieldHtml()));

            return;
        }

        // On removal there is no form that would have to be
        // prefilled again after an error.
        $prefill = $action === 'remove' ? null : $_POST;

        if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo $adminPage(
                'cms.home_page_contents',
                $cmsEditController->homePageEdit($csrf->fieldHtml(), null, $prefill, null, $csrfExpired)
            );

            return;
        }

        $result = $cmsEditController->processHomePage($action, $_POST);

        if (! $result->successful) {
            http_response_code(422);
        }

        echo $adminPage(
            'cms.home_page_contents',
            $cmsEditController->homePageEdit(
                $csrf->fieldHtml(),
                null,
                $result->successful ? null : $prefill,
                $result->successful ? $result->message : null,
                $result->successful ? null : $result->message,
            )
        );
    });
}

// Editing the rules.
$router->admin('/rules', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $cmsEditController,
    $csrf
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    echo $adminPage('admin.area.rules.title', $cmsEditController->rulesEdit($csrf->fieldHtml()));
});

$router->admin('/rules/save', static function () use (
    $isPost,
    $csrfExpired,
    $ensureAdminAccess,
    $adminPage,
    $cmsEditController,
    $csrf
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    if (! $isPost()) {
        echo $adminPage('admin.area.rules.title', $cmsEditController->rulesEdit($csrf->fieldHtml()));

        return;
    }

    $inputText = (string) ($_POST['text'] ?? '');

    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $adminPage(
            'admin.area.rules.title',
            $cmsEditController->rulesEdit($csrf->fieldHtml(), $inputText, null, $csrfExpired)
        );

        return;
    }

    $result = $cmsEditController->saveRules($_POST);

    if (! $result->successful) {
        http_response_code(422);
    }

    echo $adminPage(
        'admin.area.rules.title',
        $cmsEditController->rulesEdit(
            $csrf->fieldHtml(),
            $result->successful ? null : $inputText,
            $result->successful ? $result->message : null,
            $result->successful ? null : $result->message,
        )
    );
});

// League settings (R38): the form, saving redirects back to it so that the
// page header already shows the new name.
$router->admin('/league', static function () use ($ensureAdminAccess, $adminPage, $leagueSettingsController, $csrf): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    echo $adminPage('admin.area.league.title', $leagueSettingsController->edit($csrf->fieldHtml(), ($_GET['saved'] ?? null) === '1'));
});

$router->admin('/league/save', static function () use (
    $isPost,
    $csrfExpired,
    $ensureAdminAccess,
    $adminPage,
    $leagueSettingsController,
    $csrf
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    if (! $isPost()) {
        echo $adminPage('admin.area.league.title', $leagueSettingsController->edit($csrf->fieldHtml()));

        return;
    }

    $input = $_POST;

    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $adminPage('admin.area.league.title', $leagueSettingsController->edit($csrf->fieldHtml(), false, $input, $csrfExpired));

        return;
    }

    $result = $leagueSettingsController->save($_POST);

    if ($result->successful && $result->redirect !== null) {
        header('Location: ' . $result->redirect);
        http_response_code(303);

        return;
    }

    http_response_code(422);
    echo $adminPage('admin.area.league.title', $leagueSettingsController->edit($csrf->fieldHtml(), false, $input, $result->message));
});

// --- Season planning and lane assignment (task 35.2) ------------------------
// Overview and preview are reading (GET); applying and the matchday correction
// are writing (POST + CSRF, R22.5). All routes check write access
// (R5.1, R28.9).

/**
 * Season of a writing season planning action: from the form, otherwise the
 * currently selected season.
 */
$seasonFromForm = static function () use ($adminSeasonId): ?int {
    $seasonId = filter_var($_POST['season_id'] ?? null, FILTER_VALIDATE_INT);

    return is_int($seasonId) && $seasonId > 0 ? $seasonId : $adminSeasonId();
};

$router->admin('/season-planning', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $seasonPlanningController,
    $csrf,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    echo $adminPage('admin.area.planning.title', $seasonPlanningController->overview($csrf->fieldHtml(), $adminSeasonId()));
});

$router->admin('/season-planning/preview', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $seasonPlanningController,
    $csrf,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $seasonId = $adminSeasonId();
    if ($seasonId === null) {
        echo $adminPage('admin.area.planning.title', $seasonPlanningController->overview($csrf->fieldHtml(), null));

        return;
    }

    // Types chosen per matchday in the preview ("Neu berechnen").
    echo $adminPage('planning.preview', $seasonPlanningController->preview($csrf->fieldHtml(), $seasonId, null, $_GET['types'] ?? []));
});

$router->admin('/season-planning/apply', static function () use (
    $isPost,
    $csrfExpired,
    $ensureAdminAccess,
    $adminPage,
    $seasonPlanningController,
    $csrf,
    $seasonFromForm
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $seasonId = $seasonFromForm();

    if (! $isPost() || $seasonId === null) {
        echo $adminPage('admin.area.planning.title', $seasonPlanningController->overview($csrf->fieldHtml(), $seasonId));

        return;
    }

    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $adminPage('planning.preview', $seasonPlanningController->preview($csrf->fieldHtml(), $seasonId, $csrfExpired, $_POST['types'] ?? []));

        return;
    }

    $result = $seasonPlanningController->apply($_POST);

    if (! $result->successful) {
        // Show the preview again with an error message (e.g. missing confirmation, R9.12).
        http_response_code(422);
        echo $adminPage('planning.preview', $seasonPlanningController->preview($csrf->fieldHtml(), $seasonId, $result->message, $_POST['types'] ?? []));

        return;
    }

    echo $adminPage('admin.area.planning.title', $seasonPlanningController->overview($csrf->fieldHtml(), $seasonId, $result->message));
});

$router->admin('/season-planning/matchday', static function () use (
    $isPost,
    $csrfExpired,
    $ensureAdminAccess,
    $adminPage,
    $seasonPlanningController,
    $csrf,
    $seasonFromForm
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $seasonId = $seasonFromForm();

    if (! $isPost()) {
        echo $adminPage('admin.area.planning.title', $seasonPlanningController->overview($csrf->fieldHtml(), $seasonId));

        return;
    }

    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $adminPage('admin.area.planning.title', $seasonPlanningController->overview($csrf->fieldHtml(), $seasonId, null, $csrfExpired));

        return;
    }

    $result = $seasonPlanningController->correctMatchday($_POST);

    if (! $result->successful) {
        http_response_code(422);
    }

    echo $adminPage(
        'admin.area.planning.title',
        $seasonPlanningController->overview(
            $csrf->fieldHtml(),
            $seasonId,
            $result->successful ? $result->message : null,
            $result->successful ? null : $result->message,
        )
    );
});

// --- Season (master data, configuration, positiondays) ----------------------
// Display is reading (GET), changes are writing (POST + CSRF, R22.5). All
// routes check write access (R5.1, R28.9).
$router->admin('/season', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $seasonManagementController,
    $csrf,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    // Preview of a postponement (R8.21): `postpone` = matchday id, `date` = new date.
    $postponeId = filter_input(INPUT_GET, 'postpone', FILTER_VALIDATE_INT);
    $postponeDate = filter_input(INPUT_GET, 'date');
    $postpone = is_int($postponeId) && is_string($postponeDate) ? ['matchday_id' => $postponeId, 'date' => trim($postponeDate)] : null;

    echo $adminPage('admin.area.season.title', $seasonManagementController->page($csrf->fieldHtml(), $adminSeasonId(), postpone: $postpone));
});

$seasonActions = [
    'master-data'    => 'updateMasterData',
    'configuration' => 'updateConfiguration',
    'positionday'   => 'formPositiondayFixtures',
    'lot-draw'  => 'saveLotDrawPairings',
    'cancel'    => 'cancelMatchday',
    'reinstate' => 'reinstateMatchday',
    'postpone'  => 'postponeMatchday',
];

foreach ($seasonActions as $pathName => $method) {
    $router->admin('/season/' . $pathName, static function () use (
        $pathName,
        $method,
        $isPost,
        $csrfExpired,
        $ensureAdminAccess,
        $adminPage,
        $seasonManagementController,
        $csrf,
        $adminSeasonId
    ): void {
        if (! $ensureAdminAccess()) {
            return;
        }

        // The season of the action is in the form; otherwise the current one.
        $seasonId = filter_var($_POST['season_id'] ?? null, FILTER_VALIDATE_INT);
        $seasonId = is_int($seasonId) && $seasonId > 0 ? $seasonId : $adminSeasonId();

        if (! $isPost()) {
            echo $adminPage('admin.area.season.title', $seasonManagementController->page($csrf->fieldHtml(), $seasonId));

            return;
        }

        // After an error the inputs of the affected form are shown again.
        $prefill = $_POST + ['area' => $pathName];

        if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo $adminPage('admin.area.season.title', $seasonManagementController->page($csrf->fieldHtml(), $seasonId, null, $csrfExpired, $prefill));

            return;
        }

        $result = $seasonManagementController->{$method}($_POST);

        if (! $result->successful) {
            http_response_code(422);
        }

        echo $adminPage(
            'admin.area.season.title',
            $seasonManagementController->page(
                $csrf->fieldHtml(),
                $seasonId,
                $result->successful ? $result->message : null,
                $result->successful ? null : $result->message,
                $result->successful ? null : $prefill,
            )
        );
    });
}

// --- Honours preview (task 35.5) --------------------------------------------
// Read-only; only with a valid admin session (R28.9, R28.14).
$router->admin('/honours', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $honoursPreviewController,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    echo $adminPage('admin.area.honours.title', $honoursPreviewController->preview($adminSeasonId()));
});

// --- Result entry (task 35.3) -----------------------------------------------
// Overview and score sheet are reading (GET); results, removal and upload
// are writing (POST + CSRF, R22.5). All routes check
// write access (R5.1, R28.9).

$router->admin('/results', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $resultEntryController,
    $teamEntryController,
    $csrf,
    $adminSeasonId
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    echo $adminPage(
        'admin.area.results.title',
        $resultEntryController->overview($adminSeasonId()) . $teamEntryController->adminSeasonCard($adminSeasonId(), $csrf->fieldHtml())
    );
});

$router->admin('/results/score-sheet', static function () use (
    $ensureAdminAccess,
    $adminPage,
    $resultEntryController,
    $teamEntryController,
    $fixtureHistory,
    $baseUrl,
    $csrf
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    $fixtureId = filter_input(INPUT_GET, 'fixture', FILTER_VALIDATE_INT);
    echo $adminPage(
        'results.virtual_sheet',
        $resultEntryController->scoreSheetPage($csrf->fieldHtml(), is_int($fixtureId) ? $fixtureId : 0)
        . $teamEntryController->adminFixtureCard(is_int($fixtureId) ? $fixtureId : 0, $csrf->fieldHtml(), $baseUrl)
        . $fixtureHistory->card(is_int($fixtureId) ? $fixtureId : 0)
    );
});

$resultActions = [
    'save'   => 'saveResults',
    'remove'   => 'removeResults',
];

foreach ($resultActions as $pathName => $method) {
    $router->admin('/results/' . $pathName, static function () use (
        $method,
        $isPost,
        $csrfExpired,
        $ensureAdminAccess,
        $adminPage,
        $resultEntryController,
        $fixtureHistory,
        $csrf
    ): void {
        if (! $ensureAdminAccess()) {
            return;
        }

        $fixtureId = (int) filter_var($_POST['fixture_id'] ?? 0, FILTER_VALIDATE_INT);

        if (! $isPost()) {
            header('Location: /admin/results');
            http_response_code(302);

            return;
        }

        // After an error the entered results are shown again.
        $prefill = $method === 'saveResults' ? $_POST : null;

        if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo $adminPage(
                'results.virtual_sheet',
                $resultEntryController->scoreSheetPage($csrf->fieldHtml(), $fixtureId, $prefill, null, $csrfExpired)
            );

            return;
        }

        $result = $resultEntryController->{$method}($_POST);

        // Follow-up page, e.g. the preview of the handicap chain after a correction.
        if ($result->successful && $result->redirect !== null) {
            header('Location: ' . $result->redirect);
            http_response_code(303);

            return;
        }

        if (! $result->successful) {
            http_response_code(422);
        }

        echo $adminPage(
            'results.virtual_sheet',
            $resultEntryController->scoreSheetPage(
                $csrf->fieldHtml(),
                $fixtureId,
                $result->successful ? null : $prefill,
                $result->successful ? $result->message : null,
                $result->successful ? null : $result->message,
            )
            . $fixtureHistory->card($fixtureId)
        );
    });
}

// Handicap chain after a correction: GET shows the preview from the matchday
// of the fixture, POST applies it after explicit confirmation (R12.18, R12.19).
$router->admin('/results/handicap-chain', static function () use (
    $isPost,
    $csrfExpired,
    $ensureAdminAccess,
    $adminPage,
    $resultEntryController,
    $fixtureHistory,
    $csrf
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    if (! $isPost()) {
        $fixtureId = filter_input(INPUT_GET, 'fixture', FILTER_VALIDATE_INT);
        echo $adminPage(
            'results.chain_short',
            $resultEntryController->handicapChainPage($csrf->fieldHtml(), is_int($fixtureId) ? $fixtureId : 0)
        );

        return;
    }

    $fixtureId = (int) filter_var($_POST['fixture_id'] ?? 0, FILTER_VALIDATE_INT);

    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $adminPage('results.chain_short', $resultEntryController->handicapChainPage($csrf->fieldHtml(), $fixtureId, $csrfExpired));

        return;
    }

    $result = $resultEntryController->applyHandicapChain($_POST);
    if (! $result->successful) {
        http_response_code(422);
        echo $adminPage('results.chain_short', $resultEntryController->handicapChainPage($csrf->fieldHtml(), $fixtureId, $result->message));

        return;
    }

    echo $adminPage(
        'results.virtual_sheet',
        $resultEntryController->scoreSheetPage($csrf->fieldHtml(), $fixtureId, null, $result->message)
        . $fixtureHistory->card($fixtureId)
    );
});

// --- Result entry by the teams (R37) ---------------------------------------
// The code from the QR code is the team's authorisation; the team routes need
// neither login nor session. The code must not leak via the Referer, and the
// pages must not be cached.
$teamEntryHeaders = static function (): void {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
};

// Team page, step via `?step=` (1–3 games, 4 overview, 5 confirmation);
// `?part=1` returns only the sheet (confirmation step, refreshed by team-entry.js).
$router->public('/entry', static function () use ($t, $page, $teamEntryController, $teamEntryHeaders): void {
    $teamEntryHeaders();
    $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
    $step = filter_var($_GET['step'] ?? null, FILTER_VALIDATE_INT);
    $step = is_int($step) ? $step : null;

    if (($_GET['part'] ?? '') === '1') {
        $sheet = $teamEntryController->sheet($code, $step);
        if ($sheet === null) {
            http_response_code(404);
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $sheet ?? '';

        return;
    }

    $notice = $teamEntryController->notice(is_string($_GET['notice'] ?? null) ? $_GET['notice'] : null);
    $result = $teamEntryController->page($code, $step, $notice);
    http_response_code($result['status']);
    echo $page($t('team_entry.kicker'), $result['html']);
});

foreach (array_keys(\Bowlingliga\Presentation\TeamEntryController::ACTIONS) as $pathName) {
    $router->public('/entry/' . $pathName, static function () use ($t, $pathName, $isPost, $page, $teamEntryController, $teamEntryHeaders): void {
        $teamEntryHeaders();
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';

        // Only POST from the own pages (the code replaces the CSRF token).
        $foreign = isset($_SERVER['HTTP_SEC_FETCH_SITE']) && ! in_array($_SERVER['HTTP_SEC_FETCH_SITE'], ['same-origin', 'none'], true);
        if (! $isPost() || $foreign) {
            header('Location: /entry?code=' . rawurlencode($code));
            http_response_code(303);

            return;
        }

        $result = $teamEntryController->action($pathName, $_POST);

        // Success: on to the next step (a reload does not send the form again).
        if ($result['error'] === null) {
            header('Location: ' . \Bowlingliga\Presentation\TeamEntryController::stepUrl($code, (int) $result['step'], $result['notice']));
            http_response_code(303);

            return;
        }

        $view = $teamEntryController->page($code, $result['step'], null, $result['error']);
        http_response_code($view['status'] === 200 ? 422 : $view['status']);
        echo $page($t('team_entry.kicker'), $view['html']);
    });
}

// Admin: switch per season, takeover into the virtual score sheet, new codes.
foreach (['team-entry', 'team-entry-take-over', 'team-entry-codes'] as $pathName) {
    $router->admin('/results/' . $pathName, static function () use (
        $pathName,
        $isPost,
        $csrfExpired,
        $ensureAdminAccess,
        $adminPage,
        $resultEntryController,
        $teamEntryController,
        $fixtureHistory,
        $adminSeasonId,
        $baseUrl,
        $csrf
    ): void {
        if (! $ensureAdminAccess()) {
            return;
        }
        if (! $isPost()) {
            header('Location: /admin/results');
            http_response_code(302);

            return;
        }

        $fixtureId = (int) filter_var($_POST['fixture_id'] ?? 0, FILTER_VALIDATE_INT);
        $result = $csrf->isValid($_POST['csrf_token'] ?? null)
            ? $teamEntryController->adminAction($pathName, $_POST)
            : \Bowlingliga\Presentation\ActionResult::error($csrfExpired);
        if (! $result->successful) {
            http_response_code(422);
        }

        if ($pathName === 'team-entry') {
            echo $adminPage(
                'admin.area.results.title',
                \Bowlingliga\Presentation\AdminComponents::notes($result->successful ? $result->message : null, $result->successful ? null : $result->message)
                . $resultEntryController->overview($adminSeasonId())
                . $teamEntryController->adminSeasonCard($adminSeasonId(), $csrf->fieldHtml())
            );

            return;
        }

        echo $adminPage(
            'results.virtual_sheet',
            $resultEntryController->scoreSheetPage(
                $csrf->fieldHtml(),
                $fixtureId,
                null,
                $result->successful ? $result->message : null,
                $result->successful ? null : $result->message,
            )
            . $teamEntryController->adminFixtureCard($fixtureId, $csrf->fieldHtml(), $baseUrl)
            . $fixtureHistory->card($fixtureId)
        );
    });
}

// Upload of the original score sheet as evidence (R3.15, R3.16).
$router->admin('/results/upload', static function () use (
    $t,
    $isPost,
    $csrfExpired,
    $ensureAdminAccess,
    $adminPage,
    $resultEntryController,
    $fixtureHistory,
    $csrf
): void {
    if (! $ensureAdminAccess()) {
        return;
    }

    if (! $isPost()) {
        header('Location: /admin/results');
        http_response_code(302);

        return;
    }

    $fixtureId = (int) filter_var($_POST['fixture_id'] ?? 0, FILTER_VALIDATE_INT);

    // If the upload exceeds post_max_size, $_POST and $_FILES are empty.
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        echo $adminPage(
            'admin.area.results.title',
            '<h1>' . htmlspecialchars($t('admin.area.results.title')) . '</h1><p class="error" role="alert">'
            . htmlspecialchars($t('upload.post_too_large')) . '</p><p><a href="/admin/results">' . htmlspecialchars($t('results.to_overview')) . '</a></p>'
        );

        return;
    }

    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo $adminPage(
            'results.virtual_sheet',
            $resultEntryController->scoreSheetPage($csrf->fieldHtml(), $fixtureId, null, null, $csrfExpired)
        );

        return;
    }

    $result = $resultEntryController->saveOriginal($_POST, $_FILES['score_sheet'] ?? []);

    if (! $result->successful) {
        http_response_code(422);
    }

    echo $adminPage(
        'results.virtual_sheet',
        $resultEntryController->scoreSheetPage(
            $csrf->fieldHtml(),
            $fixtureId,
            null,
            $result->successful ? $result->message : null,
            $result->successful ? null : $result->message,
        )
        . $fixtureHistory->card($fixtureId)
    );
});

// --- Routing -------------------------------------------------------------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$handler = $router->resolve($path);
if ($handler === null) {
    $handler = $router->resolveWithId($path);
}

if ($handler === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $translator->t('common.page_not_found');

    return;
}

$handler();
