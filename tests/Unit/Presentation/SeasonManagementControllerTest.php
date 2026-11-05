<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\LotDraw\LotDrawManagement;
use Bowlingliga\Application\LotDraw\LotDrawReader;
use Bowlingliga\Application\Positionday\PositiondayManagement;
use Bowlingliga\Application\SeasonAdministration;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Positionday\PositiondayService;
use Bowlingliga\Domain\Table\TeamStatistics;
use Bowlingliga\Presentation\SeasonManagementController;
use Bowlingliga\Presentation\SeasonManagementReader;
use Bowlingliga\Presentation\SeasonManagementView;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\Unit\Application\InMemorySeasonConfigRepository;
use Bowlingliga\Tests\Unit\Application\InMemorySeasonRepository;
use Bowlingliga\Tests\Unit\Application\InMemoryTeamRepository;
use Bowlingliga\Tests\Unit\Application\InMemoryTeamSeasonRepository;
use Bowlingliga\Tests\Unit\Application\Positionday\RecordingPositiondayStore;
use Bowlingliga\Tests\Unit\Application\Positionday\FixedPositiondayContext;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the admin area "Saison".
 *
 * Validates: R8.5, R12.7, R12.8, R12.13, R31.2, R31.4, R31.7
 *
 * Season 1 has started (start 2025-09-01), season 2 only starts later
 * (start 2026-09-02); "today" is 12.10.2025.
 */
final class SeasonManagementControllerTest extends TestCase
{
    private InMemorySeasonRepository $seasons;
    private InMemorySeasonConfigRepository $config;
    private FixedPositiondayContext $context;
    private RecordingPositiondayStore $store;
    private SeasonManagementController $controller;

    protected function setUp(): void
    {
        $this->seasons = new InMemorySeasonRepository();
        $this->config  = new InMemorySeasonConfigRepository();
        $this->seasons->stash(1, 'Saison 2025/2026', '2025-09-01', '2026-06-30');
        $this->seasons->stash(2, 'Saison 2026/2027', '2026-09-02', '2027-06-30');
        $this->config->save(1, 4, '5.00', '2.50', 64, 'HDC = (Zielwert - Durchschnitt) * 0.8', 190);
        $this->config->save(2, 4, '5.00', '2.50', 64, null, 190);

        $currentTime = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2025-10-12');
        $access = new SwitchableWriteAccess(true);

        $this->context = new FixedPositiondayContext(true, [
            new TeamStatistics('11', 'Die Adler', 14, 3455, 3779, 24),
            new TeamStatistics('12', 'Eulen', 2, 3420, 3696, 24),
            new TeamStatistics('13', 'Falken', 2, 3270, 3630, 24),
            new TeamStatistics('14', 'Geisterteam', 18, 4560, 4560, 24, true),
        ], ['11' => 11, '12' => 12, '13' => 13, '14' => 14]);
        $this->store = new RecordingPositiondayStore();

        $this->controller = new SeasonManagementController(
            new SeasonAdministration(
                $this->seasons,
                $this->config,
                new InMemoryTeamSeasonRepository(),
                new InMemoryTeamRepository(),
                new Validator(),
                $access,
                $currentTime,
            ),
            new PositiondayManagement($this->context, $this->store, new PositiondayService(), new LaneAssignmentService(), $access),
            new LotDrawManagement(new FixedLotDrawReader(), $this->store, $access),
            new FixedSeasonManagementReader($this->seasons, $this->config),
            new SeasonManagementView(),
            new Validator(),
            $currentTime,
        );
    }

    public function testConfigurationBeforeSeasonStartIsSaved(): void
    {
        $result = $this->controller->updateConfiguration($this->configInput(2, ['entry_fee' => '7,50', 'handicap_factor' => '0,75']));

        self::assertTrue($result->successful, $result->message);
        $config = $this->config->find(2);
        self::assertSame('7.50', $config['entry_fee']);
        self::assertSame('HDC = (Zielwert - Durchschnitt) * 0.75', $config['handicap_formula']);
        self::assertSame(6, $config['lane_count']);
    }

    public function testEmptyFactorMeansWithoutHandicap(): void
    {
        $result = $this->controller->updateConfiguration($this->configInput(2, ['handicap_factor' => '']));

        self::assertTrue($result->successful, $result->message);
        self::assertNull($this->config->find(2)['handicap_formula'], 'R12.13: without a formula no handicap.');
    }

    public function testInvalidInputsAreRejected(): void
    {
        foreach ([
            ['lane_count' => '5'],
            ['entry_fee' => 'fünf'],
            ['handicap_factor' => '9'],
            ['target_score' => 'x'],
            ['ghost_score' => '301'],
            ['previous_season_matchdays' => '-1'],
            ['previous_season_matchdays' => '100'],
            ['positionday_interval' => '-1'],
            ['positionday_interval' => '100'],
            ['win_points' => '11'],
            ['draw_points' => '3'],
            ['loss_points' => '2'],
            ['bonus_threshold' => '10000'],
            ['bonus_step' => '0'],
            ['bonus_maximum' => '21'],
            ['bonus_maximum' => ''],
        ] as $error) {
            $result = $this->controller->updateConfiguration($this->configInput(2, $error));
            self::assertFalse($result->successful, json_encode($error));
        }
        self::assertSame('5.00', $this->config->find(2)['entry_fee'], 'Nothing was saved.');
    }

    public function testConfigurationLockedAfterSeasonStart(): void
    {
        $result = $this->controller->updateConfiguration($this->configInput(1, ['entry_fee' => '9,00']));

        self::assertFalse($result->successful, 'R12.7: no change after the season start.');
        self::assertSame('5.00', $this->config->find(1)['entry_fee']);

        $html = $this->controller->page('<input type="hidden" name="csrf_token" value="t">', 1);
        self::assertStringContainsString('kann nicht mehr geändert werden', $html);
        self::assertStringNotContainsString('action="/admin/season/configuration"', $html);
        self::assertStringContainsString('(Zielwert − Durchschnitt) × 0,8', $html);
        self::assertStringContainsString('<dd>190 Pins je Spiel</dd>', $html);
        self::assertStringContainsString('<dd>bis Spieltag 5 (HDC bis Spieltag 6)</dd>', $html);
        self::assertStringContainsString('<dt>Positionday</dt><dd>jeder 10. Spieltag</dd>', $html);
        self::assertStringContainsString('<dt>Spielpunkte</dt><dd>Sieg 2, Unentschieden 1, Niederlage 0</dd>', $html);
        self::assertStringContainsString('<dt>Bonuspunkte</dt><dd>bis 2100 Pins 1, darüber je 100 Pins einer mehr, höchstens 6</dd>', $html);
    }

    public function testMasterDataIsChangeableEvenAfterSeasonStart(): void
    {
        $result = $this->controller->updateMasterData([
            'season_id' => '1', 'name' => 'Saison 25/26', 'start_date' => '2025-09-01', 'end_date' => '2026-07-01',
        ]);

        self::assertTrue($result->successful, $result->message);
        self::assertSame('Saison 25/26', $this->seasons->find(1)['name']);
        self::assertSame('2026-07-01', $this->seasons->find(1)['end_date']);
    }

    public function testFormShowsConfigurationBeforeSeasonStart(): void
    {
        $html = $this->controller->page('<input type="hidden" name="csrf_token" value="t">', 2);

        self::assertStringContainsString('action="/admin/season/configuration"', $html);
        self::assertStringContainsString('name="entry_fee" value="5,00"', $html);
        self::assertStringContainsString('name="handicap_factor" value=""', $html, 'Without a formula the factor stays empty.');
        self::assertStringContainsString('name="ghost_score" value="190"', $html);
        self::assertStringContainsString('name="previous_season_matchdays" value="5"', $html);
        self::assertStringContainsString('name="positionday_interval" value="10"', $html);
        self::assertStringContainsString('name="win_points" value="2"', $html);
        self::assertStringContainsString('name="bonus_threshold" value="2100"', $html);
        self::assertStringContainsString('name="bonus_maximum" value="6"', $html);
    }

    /**
     * R17.11: game points and bonus scale are saved per season.
     */
    public function testPointsRulesAreSaved(): void
    {
        $result = $this->controller->updateConfiguration($this->configInput(2, [
            'win_points' => '3', 'draw_points' => '1', 'loss_points' => '0',
            'bonus_threshold' => '2000', 'bonus_step' => '50', 'bonus_maximum' => '0',
        ]));

        self::assertTrue($result->successful, $result->message);
        $config = $this->config->find(2);
        self::assertSame([3, 1, 0, 2000, 50, 0], [
            $config['win_points'], $config['draw_points'], $config['loss_points'],
            $config['bonus_threshold'], $config['bonus_step'], $config['bonus_maximum'],
        ]);
    }

    public function testGhostScoreAndPreviousSeasonSettingAreSaved(): void
    {
        $result = $this->controller->updateConfiguration($this->configInput(2, ['ghost_score' => '180', 'previous_season_matchdays' => '0']));

        self::assertTrue($result->successful, $result->message);
        self::assertSame(180, $this->config->find(2)['ghost_score']);
        self::assertSame(0, $this->config->find(2)['previous_season_matchdays']);
    }

    public function testPositiondayIntervalIsSaved(): void
    {
        $result = $this->controller->updateConfiguration($this->configInput(2, ['positionday_interval' => '8']));

        self::assertTrue($result->successful, $result->message);
        self::assertSame(8, $this->config->find(2)['positionday_interval']);
    }

    public function testLotDrawPairingsAreSaved(): void
    {
        $result = $this->controller->saveLotDrawPairings([
            'matchday_id' => '20', 'home' => ['13', '11'], 'away' => ['12', '14'],
        ]);

        self::assertTrue($result->successful, $result->message);
        self::assertSame(20, $this->store->matchdayId);
        self::assertSame(
            [[13, 12, null], [11, 14, null]],
            array_map(static fn ($f): array => [$f->homeTeamSeasonId, $f->awayTeamSeasonId, $f->lanePairIndex], $this->store->saved)
        );
    }

    public function testInvalidLotDrawPairingsAreRejected(): void
    {
        foreach ([
            [['11', '13'], ['12', '11'], 'Das Team „Adler“ ist mehrfach eingetragen; jedes Team spielt genau einmal.'],
            [['11', '0'], ['12', '0'], 'Bitte alle 2 Paarungen vollständig eintragen.'],
            [['11', '13'], ['11', '14'], 'Das Team „Adler“ kann nicht gegen sich selbst spielen.'],
            [['11', '13'], ['12', '99'], 'Ein gewähltes Team gehört nicht zu dieser Saison.'],
        ] as [$home, $away, $message]) {
            $result = $this->controller->saveLotDrawPairings(['matchday_id' => '20', 'home' => $home, 'away' => $away]);
            self::assertFalse($result->successful);
            self::assertSame($message, $result->message);
        }

        self::assertSame('Der Spieltag ist kein Losentscheid.', $this->controller->saveLotDrawPairings(['matchday_id' => '30', 'home' => ['11', '13'], 'away' => ['12', '14']])->message);
        self::assertNull($this->store->matchdayId, 'Nothing saved.');
    }

    public function testLotDrawsAreShownOnTheSeasonPage(): void
    {
        $html = $this->controller->page('<input type="hidden" name="csrf_token" value="t">', 2);

        self::assertStringContainsString('<h2>Losentscheide</h2>', $html);
        self::assertStringContainsString('action="/admin/season/lot-draw"', $html);
        self::assertSame(1, substr_count($html, 'name="home[1]"'), 'Two fixtures with four teams; only the open lot draw has a form.');
        self::assertStringContainsString('<li>Adler – Falken</li>', $html, 'A lot draw with results is read-only.');
    }

    public function testPositiondayFixturesAreFormed(): void
    {
        $result = $this->controller->formPositiondayFixtures(['matchday_id' => '3']);

        self::assertTrue($result->successful, $result->message);
        self::assertSame(3, $this->store->matchdayId);
        self::assertCount(2, $this->store->saved);
        // Tabellenplatz 1 (GhostTeam) gegen 2 (Adler), 3 (Eulen) gegen 4 (Falken).
        self::assertSame(14, $this->store->saved[0]->homeTeamSeasonId, 'The ghost team keeps its team season row.');
        self::assertSame(11, $this->store->saved[0]->awayTeamSeasonId);
    }

    public function testPositiondayNotReadyReturnsNote(): void
    {
        $this->context->previousMatchdaysComplete = false;

        $result = $this->controller->formPositiondayFixtures(['matchday_id' => '3']);

        self::assertFalse($result->successful);
        self::assertNull($this->store->saved);
    }

    public function testPositiondayWithResultsLocked(): void
    {
        $this->context->positiondayComplete = true;

        $result = $this->controller->formPositiondayFixtures(['matchday_id' => '3']);

        self::assertFalse($result->successful, 'R31.7: no new forming once results are entered.');
        self::assertNull($this->store->saved);
    }

    public function testWithoutSeasonReferenceToMasterData(): void
    {
        self::assertStringContainsString('href="/admin/master-data"', $this->controller->page('', 99));
    }

    /**
     * @param array<string, string> $deviating
     *
     * @return array<string, string>
     */
    private function configInput(int $seasonId, array $deviating = []): array
    {
        return $deviating + [
            'season_id' => (string) $seasonId,
            'lane_count' => '6',
            'entry_fee' => '5,00',
            'blind_fee' => '2,50',
            'max_handicap' => '64',
            'handicap_factor' => '0,8',
            'target_score' => '190',
            'ghost_score' => '190',
            'previous_season_matchdays' => '5',
            'positionday_interval' => '10',
            'win_points' => '2',
            'draw_points' => '1',
            'loss_points' => '0',
            'bonus_threshold' => '2100',
            'bonus_step' => '100',
            'bonus_maximum' => '6',
        ];
    }
}

/**
 * Reader fake based on the in-memory repositories; season 1 has the
 * positionday matchday 3.
 */
final class FixedSeasonManagementReader implements SeasonManagementReader
{
    public function __construct(
        private readonly InMemorySeasonRepository $seasons,
        private readonly InMemorySeasonConfigRepository $config,
    ) {
    }

    public function season(int $seasonId): ?array
    {
        $season = $this->seasons->find($seasonId);

        return $season === null ? null : [
            'id' => $seasonId, 'name' => (string) $season['name'],
            'start_date' => (string) $season['start_date'], 'end_date' => (string) $season['end_date'],
        ];
    }

    public function configuration(int $seasonId): ?array
    {
        $k = $this->config->find($seasonId);

        return $k === null ? null : [
            'lane_count' => (int) $k['lane_count'], 'entry_fee' => (string) $k['entry_fee'],
            'blind_fee' => (string) $k['blind_fee'], 'max_handicap' => (int) $k['max_handicap'],
            'handicap_formula' => $k['handicap_formula'], 'target_score' => (int) $k['target_score'],
            'ghost_score' => (int) $k['ghost_score'], 'previous_season_matchdays' => (int) $k['previous_season_matchdays'],
            'positionday_interval' => (int) $k['positionday_interval'],
            'win_points' => (int) $k['win_points'],
            'draw_points' => (int) $k['draw_points'],
            'loss_points' => (int) $k['loss_points'],
            'bonus_threshold' => (int) $k['bonus_threshold'],
            'bonus_step' => (int) $k['bonus_step'],
            'bonus_maximum' => (int) $k['bonus_maximum'],
        ];
    }

    public function positiondays(int $seasonId): array
    {
        return $seasonId !== 1 ? [] : [
            ['id' => 3, 'number' => 3, 'date' => '2025-09-24', 'ready' => true, 'locked' => false, 'pairings' => []],
        ];
    }

    public function lotDraws(int $seasonId): array
    {
        return $seasonId !== 2 ? [] : [
            ['id' => 20, 'number' => 4, 'date' => '2030-10-02', 'locked' => false, 'fixtures' => []],
            ['id' => 21, 'number' => 5, 'date' => '2030-10-09', 'locked' => true, 'fixtures' => [['home' => 11, 'away' => 12], ['home' => 13, 'away' => 14]]],
        ];
    }

    public function seasonTeams(int $seasonId): array
    {
        return [['id' => 11, 'name' => 'Adler'], ['id' => 12, 'name' => 'Falken'], ['id' => 13, 'name' => 'Eulen'], ['id' => 14, 'name' => 'Spatzen']];
    }
}

/** Lot draw matchdays 20 (season 2) and 30 (regular) with four teams. */
final class FixedLotDrawReader implements LotDrawReader
{
    public bool $results = false;

    public function matchday(int $matchdayId): ?array
    {
        return match ($matchdayId) {
            20      => ['season_id' => 2, 'number' => 4, 'type' => 'lot_draw'],
            30      => ['season_id' => 2, 'number' => 5, 'type' => 'regular'],
            default => null,
        };
    }

    public function teams(int $seasonId): array
    {
        return [['id' => 11, 'name' => 'Adler'], ['id' => 12, 'name' => 'Falken'], ['id' => 13, 'name' => 'Eulen'], ['id' => 14, 'name' => 'Spatzen']];
    }

    public function hasResults(int $matchdayId): bool
    {
        return $this->results;
    }
}
