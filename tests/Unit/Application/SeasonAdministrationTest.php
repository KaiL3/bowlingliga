<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\SeasonConfiguration;
use Bowlingliga\Application\SeasonAdministration;
use Bowlingliga\Application\SeasonAdministrationException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the writing use case of the season management (task 21.3).
 *
 * Validates: R8.1, R8.2, R8.5, R8.7, R8.9, R8.10, R8.11, R8.13, R8.15,
 * 8.16, 12.2, 12.3, 12.4, 12.7, 5.1
 *
 * Checks creating (master data + configuration including defaults), date and
 * name validation, previous season prefill and team takeover, changing the
 * configuration only before the season start, deleting only before the season
 * start and the access protection. Without a database via in-memory fakes;
 * the time is fixed for the "started" rule.
 */
final class SeasonAdministrationTest extends TestCase
{
    private InMemorySeasonRepository $seasons;
    private InMemorySeasonConfigRepository $config;
    private InMemoryTeamSeasonRepository $teamSeasons;
    private InMemoryTeamRepository $teams;
    private SwitchableWriteAccess $access;
    private \DateTimeImmutable $currentTime;
    private SeasonAdministration $management;

    protected function setUp(): void
    {
        $this->seasons     = new InMemorySeasonRepository();
        $this->config      = new InMemorySeasonConfigRepository();
        $this->teamSeasons = new InMemoryTeamSeasonRepository();
        $this->teams       = new InMemoryTeamRepository();
        $this->access     = new SwitchableWriteAccess(true);
        $this->currentTime       = new \DateTimeImmutable('2026-06-01');
        $this->management  = new SeasonAdministration(
            $this->seasons,
            $this->config,
            $this->teamSeasons,
            $this->teams,
            new Validator(),
            $this->access,
            fn (): \DateTimeImmutable => $this->currentTime,
        );
    }

    private function configuration(int $laneCount = 8, float $entryFee = 25.0, float $fee = 5.0): SeasonConfiguration
    {
        return SeasonConfiguration::generate(new Validator(), $laneCount, $entryFee, $fee);
    }

    // --- R8.1 / R12.2 / R12.3: create with defaults ---

    public function testSeasonIsCreatedWithMasterDataAndConfiguration(): void
    {
        $id = $this->management->createSeason('Saison 2026/27', '2026-09-01', '2027-05-31', null, $this->configuration());

        $season = $this->seasons->find($id);
        self::assertNotNull($season);
        self::assertSame('Saison 2026/27', $season['name']);

        $config = $this->config->find($id);
        self::assertNotNull($config);
        self::assertSame(8, $config['lane_count']);
        self::assertSame('25.00', $config['entry_fee']);
        self::assertSame('5.00', $config['blind_fee']);
    }

    public function testWithoutConfigurationDefaultsApply(): void
    {
        // No previous season, no explicit configuration => defaults (target score 200, max HDC 64,
        // ghost team 190, previous season up to matchday 5).
        $id = $this->management->createSeason('Erste Saison', '2026-09-01', '2027-05-31');

        $config = $this->config->find($id);
        self::assertNotNull($config);
        self::assertSame(SeasonConfiguration::DEFAULT_TARGET_SCORE, $config['target_score']);
        self::assertSame(SeasonConfiguration::DEFAULT_MAX_HANDICAP, $config['max_handicap']);
        self::assertSame('0.00', $config['entry_fee']);
        self::assertSame(200, $config['target_score']);
        self::assertSame(190, $config['ghost_score']);
        self::assertSame(5, $config['previous_season_matchdays']);
        self::assertSame(10, $config['positionday_interval']);
    }

    // --- R8.2: Validierung ---

    public function testEmptySeasonNameIsRejected(): void
    {
        try {
            $this->management->createSeason('   ', '2026-09-01', '2027-05-31', null, $this->configuration());
            self::fail('An empty season name should have been rejected (R8.2).');
        } catch (ValidationException $exception) {
            self::assertSame('field.season_name', $exception->field);
        }

        self::assertSame(0, $this->seasons->quantity());
    }

    public function testEndDateBeforeStartDateIsRejected(): void
    {
        try {
            $this->management->createSeason('Saison', '2026-09-01', '2026-08-01', null, $this->configuration());
            self::fail('An end date before the start date should have been rejected (R8.2).');
        } catch (ValidationException $exception) {
            self::assertSame('field.end_date', $exception->field);
        }

        self::assertSame(0, $this->seasons->quantity());
    }

    // --- R8.12 / R8.14 / R12.3: Konfigurations-Validierung ---

    public function testOddLaneCountIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->configuration(3);
    }

    public function testNegativeEntryFeeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->configuration(8, -1.0);
    }

    // --- R8.7 / R8.15 / R12.4: previous season prefill and team takeover ---

    /**
     * R8.7: the participating teams are copied with their team identity and
     * their roster; the ghost team is not (it is created anew when needed).
     */
    public function testTakeoverWithoutGhostTeamWithTeamIdentityAndRoster(): void
    {
        $roster = new InMemoryRosterRepository();
        $management = new SeasonAdministration(
            $this->seasons,
            $this->config,
            $this->teamSeasons,
            $this->teams,
            new Validator(),
            $this->access,
            fn (): \DateTimeImmutable => $this->currentTime,
            $roster,
            $roster,
        );

        $this->seasons->stash(1, 'Vorsaison', '2025-09-01', '2026-05-31');
        $eagles = $this->teams->create();
        $ghost = $this->teams->create();
        $eaglesPreviousSeason = $this->teamSeasons->create($eagles, 1, 'Die Adler');
        $this->teamSeasons->createGhostTeam($ghost, 1, 'Geisterteam');
        $roster->create($eaglesPreviousSeason, 11);
        $roster->create($eaglesPreviousSeason, 12);

        $newId = $management->createSeason('Neue Saison', '2026-09-01', '2027-05-31');

        $applied = $this->teamSeasons->findAllForSeason($newId);
        self::assertCount(1, $applied, 'The ghost team is not taken over.');
        self::assertSame('Die Adler', $applied[0]['team_name']);
        self::assertSame($eagles, $applied[0]['team_id'], 'Dasselbe Team spielt weiter.');
        self::assertSame([11, 12], $roster->findPlayerIdsForTeamSeason((int) $applied[0]['id']), 'The roster is taken over.');
    }

    public function testPreviousSeasonIsPreselectedAndTeamsCopied(): void
    {
        // Previous season with configuration and two teams.
        $this->seasons->stash(1, 'Vorsaison', '2025-09-01', '2026-05-31');
        $this->config->save(1, 6, '30.00', '7.50', 50, 'standard', 185, 175, 3, 8);
        $t1 = $this->teams->create();
        $t2 = $this->teams->create();
        $this->teamSeasons->create($t1, 1, 'Die Pins');
        $this->teamSeasons->create($t2, 1, 'Rolling Thunder');

        // New season without explicit configuration => derived from the previous season.
        $newId = $this->management->createSeason('Neue Saison', '2026-09-01', '2027-05-31');

        // R8.15 / R12.4: entry fee, fee, maximum handicap prefilled.
        $config = $this->config->find($newId);
        self::assertNotNull($config);
        self::assertSame('30.00', $config['entry_fee']);
        self::assertSame('7.50', $config['blind_fee']);
        self::assertSame(50, $config['max_handicap']);
        self::assertSame(185, $config['target_score']);
        self::assertSame(175, $config['ghost_score'], 'Ghost score from the previous season.');
        self::assertSame(3, $config['previous_season_matchdays'], 'Previous season limit from the previous season.');
        self::assertSame(8, $config['positionday_interval'], 'Positionday interval from the previous season.');

        // R8.7: teams copied including team names (new assignments for the new season).
        $applied = $this->teamSeasons->findAllForSeason($newId);
        self::assertCount(2, $applied);
        $names = array_map(static fn (array $z): string => (string) $z['team_name'], $applied);
        self::assertContains('Die Pins', $names);
        self::assertContains('Rolling Thunder', $names);
    }

    // --- R8.5: change master data ---

    public function testSeasonMasterDataCanBeChanged(): void
    {
        $id = $this->management->createSeason('Alt', '2026-09-01', '2027-05-31', null, $this->configuration());

        $this->management->updateSeason($id, 'Neu', '2026-10-01', '2027-06-30');

        $season = $this->seasons->find($id);
        self::assertSame('Neu', $season['name']);
        self::assertSame('2026-10-01', $season['start_date']);
    }

    // --- Language of the printed score sheets ---

    public function testScoreSheetLanguageIsInheritedAndChangeable(): void
    {
        $first = $this->management->createSeason('Erste', '2026-09-01', '2027-05-31', null, $this->configuration());
        self::assertNull($this->seasons->find($first)['score_sheet_language'] ?? null, 'Without a previous season the installation default applies.');

        $this->management->updateSeason($first, 'Erste', '2026-09-01', '2027-05-31', scoreSheetLanguage: 'en');
        self::assertSame('en', $this->seasons->find($first)['score_sheet_language']);
        $this->management->updateSeason($first, 'Erste neu', '2026-09-01', '2027-05-31');
        self::assertSame('en', $this->seasons->find($first)['score_sheet_language'], 'Changing the master data keeps the language.');

        $next = $this->management->createSeason('Zweite', '2027-09-01', '2028-05-31');
        self::assertSame('en', $this->seasons->find($next)['score_sheet_language'], 'A new season takes over the language of the previous one.');

        $this->expectException(ValidationException::class);
        $this->management->updateSeason($next, 'Zweite', '2027-09-01', '2028-05-31', scoreSheetLanguage: 'fr');
    }

    // --- R8.16 / R12.6 / R12.7: configuration only changeable before the start ---

    public function testConfigurationChangeableBeforeSeasonStart(): void
    {
        // Start in the future.
        $id = $this->management->createSeason('Saison', '2026-09-01', '2027-05-31', null, $this->configuration());

        $this->management->updateConfiguration($id, $this->configuration(10, 40.0, 8.0));

        self::assertSame(10, $this->config->find($id)['lane_count']);
        self::assertSame('40.00', $this->config->find($id)['entry_fee']);
    }

    public function testConfigurationChangeAfterSeasonStartIsRejected(): void
    {
        // Start in the past (today = 2026-06-01) => started.
        $id = $this->management->createSeason('Laufende Saison', '2026-01-01', '2026-12-31', null, $this->configuration());

        try {
            $this->management->updateConfiguration($id, $this->configuration(10));
            self::fail('A configuration change after the season start should have been rejected (R12.7).');
        } catch (SeasonAdministrationException) {
            // expected
        }

        self::assertSame(8, $this->config->find($id)['lane_count'], 'The configuration must stay unchanged (R12.7).');
    }

    // --- R8.9 / R8.10: deleting only before the start ---

    public function testNotStartedSeasonCanBeDeleted(): void
    {
        $id = $this->management->createSeason('Zukunft', '2026-09-01', '2027-05-31', null, $this->configuration());

        $this->management->deleteSeason($id);

        self::assertNull($this->seasons->find($id));
        self::assertNull($this->config->find($id));
    }

    public function testDeletingASeasonRemovesTheScoreSheetFilesOfItsFixtures(): void
    {
        $cleanup = new RecordingScoreSheetFileCleanup();
        $management = new SeasonAdministration(
            $this->seasons,
            $this->config,
            $this->teamSeasons,
            $this->teams,
            new Validator(),
            $this->access,
            fn (): \DateTimeImmutable => $this->currentTime,
            scoreSheetFiles: $cleanup,
        );
        $id = $management->createSeason('Zukunft', '2026-09-01', '2027-05-31', null, $this->configuration());
        self::assertSame(0, $cleanup->calls);

        $management->deleteSeason($id);

        self::assertSame(1, $cleanup->calls, 'After the cascade the unreferenced files are removed (R3.15).');
    }

    public function testStartedSeasonCannotBeDeleted(): void
    {
        $id = $this->management->createSeason('Laufend', '2026-01-01', '2026-12-31', null, $this->configuration());

        try {
            $this->management->deleteSeason($id);
            self::fail('A started season must not have been deleted (R8.10).');
        } catch (SeasonAdministrationException) {
            // expected
        }

        self::assertNotNull($this->seasons->find($id), 'The season must remain unchanged (R8.10).');
    }

    public function testDeletingOnStartDayIsRejected(): void
    {
        // Start date == today => counts as started.
        $id = $this->management->createSeason('Startet heute', '2026-06-01', '2027-05-31', null, $this->configuration());

        $this->expectException(SeasonAdministrationException::class);
        $this->management->deleteSeason($id);
    }

    // --- R5.1: Zugriffsschutz ---

    public function testWithoutWriteAccessNothingIsCreated(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        try {
            $this->management->createSeason('Saison', '2026-09-01', '2027-05-31', null, $this->configuration());
        } finally {
            self::assertSame(0, $this->seasons->quantity());
        }
    }

    public function testChangingNonExistentSeasonIsRejected(): void
    {
        $this->expectException(SeasonAdministrationException::class);
        $this->management->updateSeason(999, 'Egal', '2026-09-01', '2027-05-31');
    }
}
