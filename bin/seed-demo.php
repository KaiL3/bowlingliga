<?php

declare(strict_types=1);

/**
 * Fills an empty database with a fictional demo league, so that the website
 * can be tried out and shown (screenshots) without real league data.
 *
 * Usage:
 *   php bin/seed-demo.php
 *
 * Creates one season with six fictional teams of five players each, plans it
 * (round robin with lane assignment, weekly on the weekday of the start) and
 * confirms the results of every matchday that lies in the past – through the
 * same use cases as the admin area, so handicaps are frozen, standings and
 * honours computed exactly as in real use. Also a news post and a rules text
 * in the installation's default language (`language` in the configuration).
 *
 * The season starts six weeks before today, so about half of its ten
 * matchdays are played. The script refuses to run if the database already
 * holds seasons: empty it first with `php bin/reset-database.php --yes`
 * (admin accounts are kept). Admin accounts are not created here; use
 * `php bin/create-admin.php <username>`.
 *
 * Configuration like the website: config/database.php or the file from the
 * environment variable BOWLINGLIGA_CONFIG. The results are random but the same
 * on every run (fixed seed).
 */

use Bowlingliga\Application\Cms\HomePagePostManagement;
use Bowlingliga\Application\Cms\RulesManagement;
use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\ResultEntry\EnteredGame;
use Bowlingliga\Application\ResultEntry\EnteredResults;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Application\SeasonAdministration;
use Bowlingliga\Application\SeasonConfiguration;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningManagement;
use Bowlingliga\Application\TeamManagement;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Persistence\CacheRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\FixtureRepository;
use Bowlingliga\Persistence\FrozenHandicapRepository;
use Bowlingliga\Persistence\HomePagePostRepository;
use Bowlingliga\Persistence\MatchdayRepository;
use Bowlingliga\Persistence\PdoTransaction;
use Bowlingliga\Persistence\PlayerRepository;
use Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository;
use Bowlingliga\Persistence\ResultRepository;
use Bowlingliga\Persistence\RosterRepository;
use Bowlingliga\Persistence\RulesRepository;
use Bowlingliga\Persistence\SeasonConfigRepository;
use Bowlingliga\Persistence\SeasonPlanning\SeasonPlanningDataRepository;
use Bowlingliga\Persistence\SeasonPlanRepository;
use Bowlingliga\Persistence\SeasonRepository;
use Bowlingliga\Persistence\TeamRepository;
use Bowlingliga\Persistence\TeamSeasonRepository;
use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\WriteAccess;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$configFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
if (! is_file($configFile)) {
    fwrite(STDERR, "Configuration missing: {$configFile}\n");
    exit(1);
}
/** @var array<string, mixed> $config */
$config = require $configFile;
$database = new Database($config);
$german = ($config['language'] ?? 'de') === 'de';

$seasons = new SeasonRepository($database);
if ($seasons->findAllSeasons() !== []) {
    fwrite(STDERR, "The database already holds seasons. Empty it first: php bin/reset-database.php --yes\n");
    exit(1);
}

// --- Wiring (as in public/index.php, with write access for this script) ----

$access = new class implements WriteAccess {
    public function checkWriteAccess(): bool
    {
        return true;
    }
};
$validator   = new Validator();
$teamSeasons = new TeamSeasonRepository($database);
$teams       = new TeamRepository($database);
$seasonConfig = new SeasonConfigRepository($database);
$roster      = new RosterRepository($database);
$players     = new PlayerRepository($database);
$matchdays   = new MatchdayRepository($database);
$fixtures    = new FixtureRepository($database);
$handicaps   = new HandicapFreezingRepository($database, new FrozenHandicapRepository($database));
$seasonPlan  = new SeasonPlanRepository($database);

$seasonAdministration = new SeasonAdministration($seasons, $seasonConfig, $teamSeasons, $teams, $validator, $access, rosterReader: $roster, rosterStore: $roster);
$teamManagement = new TeamManagement($teams, $teamSeasons, $seasons, $validator, $access);
$playerManagement = new PlayerManagement($players, $roster, $validator, $access);
$planning = new SeasonPlanningManagement(
    new SeasonPlanningDataRepository($database, $seasonPlan),
    $seasonPlan,
    new SeasonPlanner(),
    new LaneAssignmentService(),
    $access,
);
$results = new ResultRecording(
    new ResultRepository($database),
    $fixtures,
    $matchdays,
    $matchdays,
    $handicaps,
    new CacheManager(new CacheRepository($database)),
    $validator,
    $access,
    $roster,
    $handicaps,
    new PdoTransaction($database),
);

// --- Season ------------------------------------------------------------------

$today = new DateTimeImmutable('today');
$start = $today->modify('-6 weeks');
$seasonId = $seasonAdministration->createSeason(
    $german ? 'Demo-Saison ' . $start->format('Y') : 'Demo season ' . $start->format('Y'),
    $start->format('Y-m-d'),
    $start->modify('+12 weeks')->format('Y-m-d'),
    10,
    SeasonConfiguration::generate($validator, 6, 15.0, 7.5, handicapFormula: 'standard', targetScore: 200, ghostScore: 190, previousSeasonMatchdays: 0, positiondayInterval: 0),
);
echo "Season created ({$start->format('Y-m-d')}, 10 matchdays).\n";

// --- Teams and players (fictional); the number is each player's strength ---

mt_srand(2026);
$league = [
    'Pin Pirates'     => ['Alex Brandt' => 182, 'Mia Keller' => 158, 'Jonas Weber' => 171, 'Lena Fuchs' => 149, 'Tom Richter' => 165],
    'Strike Force'    => ['Nina Vogel' => 176, 'Paul Hartmann' => 189, 'Sara Lang' => 152, 'Felix Busch' => 168, 'Eva Roth' => 144],
    'Gutter Kings'    => ['Ben Schulz' => 161, 'Clara Wolf' => 155, 'David Krause' => 173, 'Hanna Berg' => 140, 'Ole Peters' => 166],
    'Spare Parts'     => ['Lukas Frank' => 178, 'Emma Scholz' => 163, 'Max Kaiser' => 185, 'Julia Horn' => 150, 'Finn Lorenz' => 159],
    'Lucky Lanes'     => ['Sophie Haas' => 169, 'Noah Graf' => 157, 'Lea Brandt' => 146, 'Elias Moser' => 180, 'Ida Pohl' => 153],
    'Rolling Thunder' => ['Tim Sauer' => 187, 'Marie Kuhn' => 162, 'Leon Arndt' => 170, 'Anna Ebert' => 148, 'Jan Ritter' => 175],
];
$strength = [];
$rosterOf = [];
foreach ($league as $teamName => $members) {
    $teamSeasonId = $teamManagement->createTeam($seasonId, $teamName);
    foreach ($members as $name => $average) {
        $playerId = $playerManagement->createPlayer($name, in_array(explode(' ', $name)[0], ['Mia', 'Lena', 'Nina', 'Sara', 'Eva', 'Clara', 'Hanna', 'Emma', 'Julia', 'Sophie', 'Lea', 'Ida', 'Marie', 'Anna'], true) ? 'female' : 'male');
        $playerManagement->assignPlayerToTeamSeason($teamSeasonId, $playerId);
        $strength[$playerId] = $average;
        $rosterOf[$teamSeasonId][$playerId] = $name;
    }
}
echo count($league) . " teams with 5 players each created.\n";

// --- Schedule with lane assignment ---------------------------------------------

$planning->applyPlan($planning->planPreview($seasonId), true);
echo "Schedule and lanes planned.\n";

// --- Results of the matchdays in the past ------------------------------------

$played = 0;
foreach ($matchdays->findAllForSeason($seasonId) as $matchday) {
    if ((string) $matchday['date'] >= $today->format('Y-m-d')) {
        continue;
    }
    foreach ($fixtures->findAllForMatchday((int) $matchday['id']) as $fixture) {
        $games = [];
        foreach ([(int) $fixture['home_team_season_id'], (int) $fixture['away_team_season_id']] as $teamSeasonId) {
            // Four of the five play the whole evening; who sits out changes.
            $lineup = array_keys($rosterOf[$teamSeasonId]);
            unset($lineup[((int) $matchday['matchday_number'] + $teamSeasonId) % 5]);
            foreach ($lineup as $playerId) {
                foreach ([1, 2, 3] as $game) {
                    $net = max(80, min(279, $strength[$playerId] + mt_rand(-35, 35)));
                    $games[] = EnteredGame::player($teamSeasonId, $playerId, $game, $net);
                }
            }
        }
        $results->confirmAndSave(new EnteredResults((int) $fixture['id'], (int) $matchday['id'], $games));
    }
    $played++;
}
echo "Results of {$played} matchdays confirmed.\n";

// --- News and rules ------------------------------------------------------------

$sanitizer = new HtmlSanitizer();
(new HomePagePostManagement(new HomePagePostRepository($database), $validator, $sanitizer, $access))->add(
    $german ? 'Willkommen in der Demo-Liga' : 'Welcome to the demo league',
    $german
        ? '<p>Diese Liga ist erfunden: Teams, Spieler und Ergebnisse dienen nur zum Ausprobieren der Website.</p>'
        : '<p>This league is made up: teams, players and results are only there to try out the website.</p>',
);
(new RulesManagement(new RulesRepository($database), $validator, $sanitizer, $access))->save($german
    ? "<h2>Spielregeln der Demo-Liga</h2>\n<p>Jedes Team spielt mit vier Spielern drei Spiele. Je Spiel gibt es Punkte für das höhere Brutto-Ergebnis, dazu Bonuspunkte für die Gesamtpins.</p>"
    : "<h2>Rules of the demo league</h2>\n<p>Every team plays three games with four players. Each game earns points for the higher gross score, plus bonus points for the total pins.</p>");
echo "News post and rules added.\n";

// The demo league is fictional, so its players are public (R40.1).
$demoSettings = new \Bowlingliga\Persistence\LeagueSettingsRepository($database);
$demoSettings->save(\Bowlingliga\Application\League\LeagueSettings::nameFrom($demoSettings), true);
echo "Player data made public.\nDone. Create an admin account with: php bin/create-admin.php <username>\n";
