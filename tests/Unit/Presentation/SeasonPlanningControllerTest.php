<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\MatchdayManagement;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningManagement;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Presentation\SeasonPlanningController;
use Bowlingliga\Presentation\SeasonPlanningView;
use Bowlingliga\Tests\Unit\Application\InMemoryMatchdayRepository;
use Bowlingliga\Tests\Unit\Application\InMemorySeasonRepository;
use Bowlingliga\Tests\Unit\Application\SeasonPlanning\FixedSeasonTeamsReader;
use Bowlingliga\Tests\Unit\Application\SeasonPlanning\RecordingSeasonPlanStore;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see SeasonPlanningController} (task 35.2).
 *
 * Validates: R28.11, R9.3, R9.12, R9.14, R18.9, R18.10, R8.19, R5.1
 *
 * Checks preview and apply via the real use case including real domain
 * services (season planner, lane assignment) against in-memory ports:
 * fingerprint comparison, mandatory confirmation for warnings, access
 * protection and the matchday correction before the season start.
 */
final class SeasonPlanningControllerTest extends TestCase
{
    private const CSRF = '<input type="hidden" name="csrf_token" value="t">';
    private const SEASON = 1;

    private SwitchableWriteAccess $access;
    private RecordingSeasonPlanStore $store;
    private FixedSeasonPlanningReader $reader;
    private InMemoryMatchdayRepository $matchdays;

    protected function setUp(): void
    {
        $this->access   = new SwitchableWriteAccess(true);
        $this->store  = new RecordingSeasonPlanStore();
        $this->matchdays = new InMemoryMatchdayRepository();
        $this->reader     = new FixedSeasonPlanningReader(
            [
                'id' => self::SEASON, 'name' => 'Saison 2030', 'start_date' => '2030-09-04',
                'end_date' => '2031-05-28', 'team_count' => 3, 'matchday_count' => 6,
                'lane_count' => 4, 'started' => false,
            ],
            [0 => 'Geisterteam', 11 => 'Adler', 12 => 'Falken', 13 => 'Eulen'],
        );
    }

    /**
     * Builds the controller with a matchday count. 3 teams + ghost team give
     * rounds of 3 matchdays each; 6 is even, 5 produces a distribution warning
     * (R9.12).
     */
    private function controller(int $matchdayCount = 6): SeasonPlanningController
    {
        $teams = [
            ['team_season_id' => 11, 'team_name' => 'Adler'],
            ['team_season_id' => 12, 'team_name' => 'Falken'],
            ['team_season_id' => 13, 'team_name' => 'Eulen'],
        ];

        $seasons = new InMemorySeasonRepository();
        $seasons->stash(self::SEASON, 'Saison 2030', '2030-09-04', '2031-05-28');

        return new SeasonPlanningController(
            new SeasonPlanningManagement(
                new FixedSeasonTeamsReader($teams, $matchdayCount, 4),
                $this->store,
                new SeasonPlanner(),
                new LaneAssignmentService(),
                $this->access,
            ),
            new MatchdayManagement($this->matchdays, $seasons, $this->access),
            $this->reader,
            new SeasonPlanningView(),
        );
    }

    private function fingerprintFromPreview(string $html): string
    {
        self::assertSame(1, preg_match('/name="fingerprint" value="([0-9a-f]{64})"/', $html, $matches));

        return $matches[1];
    }

    public function testPreviewShowsPlanWithTeamNamesAndLanes(): void
    {
        $html = $this->controller()->preview(self::CSRF, self::SEASON);

        self::assertStringContainsString('Spielplan-Vorschau', $html);
        self::assertStringContainsString('Adler', $html);
        self::assertStringContainsString('Geisterteam', $html);
        self::assertStringContainsString('(Bahn 1/2)', $html);
        self::assertStringContainsString('action="/admin/season-planning/apply"', $html);
        self::assertNull($this->store->saved, 'The preview saves nothing (R9.14).');
    }

    public function testApplyingWithMatchingFingerprintSaves(): void
    {
        $controller = $this->controller();
        $fingerprint = $this->fingerprintFromPreview($controller->preview(self::CSRF, self::SEASON));

        $result = $controller->apply(['season_id' => '1', 'fingerprint' => $fingerprint]);

        self::assertTrue($result->successful, $result->message);
        self::assertSame(self::SEASON, $this->store->savedSeasonId);
        self::assertCount(6, $this->store->saved);
    }

    public function testChangedBasisPreventsApply(): void
    {
        $fingerprint = $this->fingerprintFromPreview($this->controller(6)->preview(self::CSRF, self::SEASON));

        // The season length changes between preview and apply.
        $result = $this->controller(9)->apply(['season_id' => '1', 'fingerprint' => $fingerprint]);

        self::assertFalse($result->successful);
        self::assertStringContainsString('geändert', $result->message);
        self::assertNull($this->store->saved);
    }

    public function testWarningRequiresConfirmation(): void
    {
        $controller = $this->controller(5);
        $html = $controller->preview(self::CSRF, self::SEASON);
        self::assertStringContainsString('Warnung:', $html);
        self::assertStringContainsString('name="confirmed"', $html);

        $fingerprint = $this->fingerprintFromPreview($html);

        $without = $controller->apply(['season_id' => '1', 'fingerprint' => $fingerprint]);
        self::assertFalse($without->successful);
        self::assertNull($this->store->saved);

        $with = $controller->apply(['season_id' => '1', 'fingerprint' => $fingerprint, 'confirmed' => '1']);
        self::assertTrue($with->successful, $with->message);
        self::assertCount(5, $this->store->saved);
    }

    public function testWithoutWriteAccessNothingIsSaved(): void
    {
        $controller = $this->controller();
        $fingerprint = $this->fingerprintFromPreview($controller->preview(self::CSRF, self::SEASON));
        $this->access->allowed = false;

        $result = $controller->apply(['season_id' => '1', 'fingerprint' => $fingerprint]);

        self::assertFalse($result->successful);
        self::assertNull($this->store->saved);
    }

    public function testLockReasonPreventsPreview(): void
    {
        $this->reader->reason = new Message('planning.results_entered');

        $html = $this->controller()->preview(self::CSRF, self::SEASON);

        self::assertStringContainsString('bereits Ergebnisse erfasst', $html);
        self::assertStringNotContainsString('action="/admin/season-planning/apply"', $html);
    }

    public function testMissingLaneCountLeadsToErrorMessage(): void
    {
        $controller = new SeasonPlanningController(
            new SeasonPlanningManagement(
                new FixedSeasonTeamsReader([
                    ['team_season_id' => 11, 'team_name' => 'Adler'],
                    ['team_season_id' => 12, 'team_name' => 'Falken'],
                ], 3, null),
                $this->store,
                new SeasonPlanner(),
                new LaneAssignmentService(),
                $this->access,
            ),
            new MatchdayManagement($this->matchdays, new InMemorySeasonRepository(), $this->access),
            $this->reader,
            new SeasonPlanningView(),
        );

        $html = $controller->preview(self::CSRF, self::SEASON);

        self::assertStringContainsString('class="error"', $html);
        self::assertStringNotContainsString('name="fingerprint"', $html);
    }

    public function testCorrectionChangesTheDateButKeepsTheType(): void
    {
        $id = $this->matchdays->create(self::SEASON, 1, '2030-09-04', 'lot_draw');
        $this->reader->plan = [$this->planEntry($id, 'lot_draw')];

        $result = $this->controller()->correctMatchday([
            'season_id' => '1', 'matchday_id' => (string) $id, 'date' => '2030-09-11', 'matchday_type' => 'regular',
        ]);

        self::assertTrue($result->successful, $result->message);
        self::assertSame('lot_draw', $this->matchdays->find($id)['matchday_type'], 'The type is set in the preview.');
        self::assertSame('2030-09-11', $this->matchdays->find($id)['date']);
    }

    public function testCorrectionAfterSeasonStartIsRejected(): void
    {
        $id = $this->matchdays->create(self::SEASON, 1, '2030-09-04');
        $this->reader->plan = [$this->planEntry($id, 'regular')];
        $this->reader->seasonData['started'] = true;

        $result = $this->controller()->correctMatchday([
            'season_id' => '1', 'matchday_id' => (string) $id, 'date' => '2030-09-11', 'matchday_type' => 'lot_draw',
        ]);

        self::assertFalse($result->successful);
        self::assertSame('regular', $this->matchdays->find($id)['matchday_type']);
    }

    public function testPositiondayStaysPositiondayOnCorrection(): void
    {
        $id = $this->matchdays->create(self::SEASON, 10, '2030-11-06', 'positionday');
        $this->reader->plan = [$this->planEntry($id, 'positionday')];

        $result = $this->controller()->correctMatchday([
            'season_id' => '1', 'matchday_id' => (string) $id, 'date' => '2030-11-13', 'matchday_type' => 'regular',
        ]);

        self::assertTrue($result->successful, $result->message);
        self::assertSame('positionday', $this->matchdays->find($id)['matchday_type']);
        self::assertSame('2030-11-13', $this->matchdays->find($id)['date']);
    }

    public function testTypesFromTheFormAreFiltered(): void
    {
        self::assertSame(
            [2 => 'lot_draw', 5 => 'positionday', 7 => 'regular'],
            SeasonPlanningController::types([2 => 'lot_draw', 5 => 'positionday', 7 => 'regular', 8 => 'quatsch', 0 => 'regular', 'x' => 'regular', 9 => ['regular']])
        );
        self::assertSame([], SeasonPlanningController::types('lot_draw'));
    }

    public function testForeignMatchdayIsRejected(): void
    {
        $id = $this->matchdays->create(self::SEASON, 1, '2030-09-04');
        $this->reader->plan = [];

        $result = $this->controller()->correctMatchday([
            'season_id' => '1', 'matchday_id' => (string) $id, 'date' => '2030-09-11', 'matchday_type' => 'regular',
        ]);

        self::assertFalse($result->successful);
    }

    public function testOverviewWithoutSeasonShowsNote(): void
    {
        $html = $this->controller()->overview(self::CSRF, null);

        self::assertStringContainsString('keine Saison gewählt', $html);
    }

    public function testOverviewShowsStoredPlanWithCorrection(): void
    {
        $this->reader->plan = [$this->planEntry(5, 'regular', [
            ['home' => 'Adler', 'away' => 'Falken', 'lanes' => '3/4', 'status' => 'open'],
        ])];

        $html = $this->controller()->overview(self::CSRF, self::SEASON);

        self::assertStringContainsString('Adler – Falken', $html);
        self::assertStringContainsString('(Bahn 3/4)', $html);
        self::assertStringContainsString('action="/admin/season-planning/matchday"', $html);
        self::assertStringContainsString('Neu planen', $html);
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     *
     * @return array<string, mixed>
     */
    private function planEntry(int $id, string $type, array $fixtures = []): array
    {
        return [
            'id' => $id, 'matchday_number' => 1, 'date' => '2030-09-04', 'matchday_type' => $type,
            'is_played' => false, 'pairings' => $fixtures,
        ];
    }
}
