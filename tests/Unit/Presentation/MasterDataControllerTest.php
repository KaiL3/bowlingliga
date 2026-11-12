<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\AdminAccountManagement;
use Bowlingliga\Application\RosterStore;
use Bowlingliga\Application\SeasonConfigStore;
use Bowlingliga\Application\SeasonReader;
use Bowlingliga\Application\SeasonStore;
use Bowlingliga\Application\SeasonAdministration;
use Bowlingliga\Application\PlayerStore;
use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\MatchdayStore;
use Bowlingliga\Application\MatchdayManagement;
use Bowlingliga\Application\TeamSeasonStore;
use Bowlingliga\Application\TeamStore;
use Bowlingliga\Application\TeamManagement;
use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Presentation\MasterDataController;
use Bowlingliga\Presentation\MasterDataReader;
use Bowlingliga\Presentation\MasterDataView;
use Bowlingliga\Security\AccountManagement;
use Bowlingliga\Security\AccountPermissions;
use Bowlingliga\Security\Permission;
use Bowlingliga\Tests\Unit\Application\ImmediateTransaction;
use Bowlingliga\Tests\Unit\Security\FixedLoggedInAccount;
use Bowlingliga\Tests\Unit\Security\InMemoryAccountLogStore;
use Bowlingliga\Tests\Unit\Security\InMemoryPermissionStore;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\TotpSecretEncryption;
use Bowlingliga\Security\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see MasterDataController} (task 35.1).
 *
 * Validates: R28.10, R5.1, R23.2, R6.1
 *
 * Checks the complete run of a writing master data action through the real
 * use cases (tasks 21.1–21.4) against in-memory fakes of the application
 * ports: success messages, translating domain exceptions into error messages
 * (without state change) and the access protection (R5.1).
 */
final class MasterDataControllerTest extends TestCase
{
    private MasterDataWriteAccess $access;
    private InMemoryPlayerStore $playerStore;
    private InMemoryAccountsRepo $accountsRepo;
    private MasterDataController $controller;
    private InMemoryPermissionStore $permissions;
    private FixedLoggedInAccount $current;
    private int $chief;

    protected function setUp(): void
    {
        if (! TotpSecretEncryption::isAvailable()) {
            self::markTestSkipped('libsodium is not available.');
        }

        $this->access         = new MasterDataWriteAccess(true);
        $validator             = new Validator();
        $this->playerStore = new InMemoryPlayerStore();
        $teamSeason            = new InMemoryTeamSeasonStore();
        $season                = new InMemorySeasonStore();
        $matchday              = new InMemoryMatchdayStore();
        $this->accountsRepo      = new InMemoryAccountsRepo();

        $reader = new InMemoryMasterDataReader($this->playerStore, $teamSeason, $season, $matchday, $this->accountsRepo);

        // The logged-in account may manage accounts.
        $this->chief       = $this->accountsRepo->create('chef', (new PasswordHasher())->hash('passwort-chef'));
        $this->permissions = new InMemoryPermissionStore();
        $this->permissions->replace($this->chief, [Permission::SystemAdmin->value]);
        $this->current     = new FixedLoggedInAccount($this->chief);

        $accountManagement = new AdminAccountManagement(
            new AccountManagement(
                $this->accountsRepo,
                new PasswordHasher(),
                new TotpSecretEncryption(TotpSecretEncryption::generateKey()),
            ),
            $this->access,
            new AccountPermissions($this->permissions, $this->current),
            new InMemoryAccountLogStore(),
            new ImmediateTransaction(),
        );

        $this->controller = new MasterDataController(
            new PlayerManagement($this->playerStore, new InMemoryRosterStore(), $validator, $this->access),
            new TeamManagement(new InMemoryTeamStore(), $teamSeason, $season, $validator, $this->access),
            new SeasonAdministration($season, new NullSeasonConfigStore(), $teamSeason, new InMemoryTeamStore(), $validator, $this->access),
            new MatchdayManagement($matchday, $season, $this->access),
            $accountManagement,
            $reader,
            new MasterDataView(),
        );
    }

    public function testCreatePlayerReturnsSuccess(): void
    {
        $result = $this->controller->process('player-create', [
            'name'       => 'Anna',
            'gender' => 'female',
        ]);

        self::assertTrue($result->successful);
        self::assertCount(1, $this->playerStore->all());
    }

    public function testInvalidPlayerNameReturnsErrorWithoutChange(): void
    {
        $result = $this->controller->process('player-create', [
            'name'       => '',
            'gender' => 'female',
        ]);

        self::assertFalse($result->successful);
        self::assertNotSame('', $result->message);
        self::assertCount(0, $this->playerStore->all(), 'With invalid input nothing may be created.');
    }

    public function testCreateAccountAndDuplicateUsernameBecomesError(): void
    {
        $success = $this->controller->process('account-create', [
            'username' => 'admin',
            'password'     => 'geheim123',
        ]);
        self::assertTrue($success->successful);

        $duplicate = $this->controller->process('account-create', [
            'username' => 'admin',
            'password'     => 'anders123',
        ]);

        self::assertFalse($duplicate->successful);
        self::assertStringContainsString('bereits vergeben', $duplicate->message);
        self::assertSame(2, $this->accountsRepo->countItems());
    }

    public function testTheAccountsTabShowsPermissionsAndLog(): void
    {
        $this->controller->process('account-create', ['username' => 'helfer', 'password' => 'geheim123']);
        $helper = $this->accountsRepo->findByUsername('helfer')['id'] ?? 0;

        $saved = $this->controller->process('account-permissions', ['account_id' => (string) $helper, 'permissions' => ['system.admin']]);
        self::assertTrue($saved->successful, $saved->message);
        self::assertSame('Die Rechte von helfer sind gespeichert.', $saved->message);

        $html = $this->controller->overview('<input name="csrf_token">', null, null, null, 'accounts');
        self::assertStringContainsString('<span class="badge">eigenes Konto</span>', $html);
        self::assertSame(2, substr_count($html, 'name="permissions[]" value="system.admin" checked'), 'Both accounts hold it.');
        self::assertStringContainsString('chef hat helfer das Recht „Systemadmin“ gegeben.', $html);
        self::assertStringContainsString('chef hat das Konto helfer angelegt.', $html);
    }

    public function testWithoutThePermissionTheAccountsTabIsGone(): void
    {
        $this->permissions->replace($this->chief, []);

        $html = $this->controller->overview('<input name="csrf_token">', null, null, null, 'accounts');
        self::assertStringNotContainsString('tab=accounts', $html);
        self::assertStringNotContainsString('account-create', $html);
        self::assertStringContainsString('Dafür fehlt diesem Konto das Recht „Systemadmin“.', $html);

        $result = $this->controller->process('account-create', ['username' => 'neu', 'password' => 'geheim123']);
        self::assertFalse($result->successful);
        self::assertSame(1, $this->accountsRepo->countItems());
    }

    public function testWithoutWriteAccessReturnsError(): void
    {
        $this->access->allowed = false;

        $result = $this->controller->process('player-create', [
            'name'       => 'Anna',
            'gender' => 'female',
        ]);

        self::assertFalse($result->successful);
        self::assertCount(0, $this->playerStore->all());
    }

    public function testUnknownActionReturnsError(): void
    {
        $result = $this->controller->process('gibt-es-nicht', []);

        self::assertFalse($result->successful);
    }

    public function testMissingIdOnRemoveReturnsError(): void
    {
        $result = $this->controller->process('player-remove', []);

        self::assertFalse($result->successful);
    }

    /**
     * After saving, the tab of the action stays selected.
     */
    public function testTabForAction(): void
    {
        self::assertSame('players', MasterDataController::tabForAction('player-create'));
        self::assertSame('seasons', MasterDataController::tabForAction('season-remove'));
        self::assertSame('teams', MasterDataController::tabForAction('roster-assign'));
        self::assertSame('matchdays', MasterDataController::tabForAction('matchday-create'));
        self::assertSame('accounts', MasterDataController::tabForAction('account-remove'));
        self::assertSame('players', MasterDataController::tabForAction('unbekannt'));

        $html = $this->controller->overview('<input name="csrf_token">', null, 'Adminkonto wurde angelegt.', null, 'accounts');
        self::assertStringContainsString('tab=accounts" aria-current="page"', $html);
        self::assertStringContainsString('Adminkonto wurde angelegt.', $html);
    }

    public function testOverviewContainsCreatedPlayer(): void
    {
        $this->controller->process('player-create', ['name' => 'Bea', 'gender' => 'female']);

        $html = $this->controller->overview('<input name="csrf_token">', null);

        self::assertStringContainsString('Bea', $html);
    }
}

/** Switchable write access for the controller tests. */
final class MasterDataWriteAccess implements WriteAccess
{
    public function __construct(public bool $allowed)
    {
    }

    public function checkWriteAccess(): bool
    {
        return $this->allowed;
    }
}

/** In-Memory-Spielerspeicher (Application-Port). */
final class InMemoryPlayerStore implements PlayerStore
{
    /** @var array<int, array<string, mixed>> */
    private array $player = [];
    private int $id = 1;

    public function create(string $name, string $gender): int
    {
        $id = $this->id++;
        $this->player[$id] = ['id' => $id, 'name' => $name, 'gender' => $gender];

        return $id;
    }

    public function update(int $id, string $name, string $gender): void
    {
        $this->player[$id] = ['id' => $id, 'name' => $name, 'gender' => $gender];
    }

    public function raiseSessionVersion(int $id): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['session_version']++;
        }
    }

    public function delete(int $id): void
    {
        unset($this->player[$id]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->player);
    }
}

/** In-Memory-Kaderspeicher (Application-Port). */
final class InMemoryRosterStore implements RosterStore
{
    private int $id = 1;

    public function create(int $teamSeasonId, int $playerId): int
    {
        return $this->id++;
    }

    public function delete(int $id): void
    {
    }

    public function findAssignmentInSeason(int $teamSeasonId, int $playerId): ?array
    {
        return null;
    }
}

/** In-Memory-Teamspeicher (Application-Port). */
final class InMemoryTeamStore implements TeamStore
{
    private int $id = 1;

    public function create(): int
    {
        return $this->id++;
    }

    public function delete(int $id): void
    {
    }

    public function find(int $id): ?array
    {
        return ['id' => $id];
    }
}

/** In-Memory-TeamSaison-Speicher (Application-Port). */
final class InMemoryTeamSeasonStore implements TeamSeasonStore
{
    /** @var array<int, array<string, mixed>> */
    private array $assignments = [];
    private int $id = 1;

    public function create(int $teamId, int $seasonId, string $teamLabel): int
    {
        $id = $this->id++;
        $this->assignments[$id] = ['id' => $id, 'team_id' => $teamId, 'season_id' => $seasonId, 'team_name' => $teamLabel];

        return $id;
    }

    public function updateTeamName(int $id, string $teamLabel): void
    {
        if (isset($this->assignments[$id])) {
            $this->assignments[$id]['team_name'] = $teamLabel;
        }
    }

    public function delete(int $id): void
    {
        unset($this->assignments[$id]);
    }

    public function find(int $id): ?array
    {
        return $this->assignments[$id] ?? null;
    }

    public function findAllForSeason(int $seasonId): array
    {
        return array_values(array_filter(
            $this->assignments,
            static fn (array $z): bool => (int) $z['season_id'] === $seasonId,
        ));
    }
}

/** In-Memory-Saisonspeicher/-leser (Application-Ports). */
final class InMemorySeasonStore implements SeasonStore, SeasonReader
{
    /** @var array<int, array<string, mixed>> */
    private array $seasons = [];
    private int $id = 1;

    public function create(string $name, string $startDate, string $endDate, ?int $matchdayCount = null): int
    {
        $id = $this->id++;
        $this->seasons[$id] = ['id' => $id, 'name' => $name, 'start_date' => $startDate, 'end_date' => $endDate];

        return $id;
    }

    public function update(int $id, string $name, string $startDate, string $endDate, ?int $matchdayCount = null): void
    {
        $this->seasons[$id] = ['id' => $id, 'name' => $name, 'start_date' => $startDate, 'end_date' => $endDate];
    }

    public function delete(int $id): void
    {
        unset($this->seasons[$id]);
    }

    public function setScoreSheetLanguage(int $id, ?string $language): void
    {
        if (isset($this->seasons[$id])) {
            $this->seasons[$id]['score_sheet_language'] = $language;
        }
    }

    public function find(int $id): ?array
    {
        return $this->seasons[$id] ?? null;
    }

    public function findAllSeasons(): array
    {
        return array_values($this->seasons);
    }
}

/** In-Memory-Spieltagspeicher (Application-Port). */
final class InMemoryMatchdayStore implements MatchdayStore
{
    /** @var array<int, array<string, mixed>> */
    private array $matchdays = [];
    private int $id = 1;

    public function create(
        int $seasonId,
        int $matchdayCounter,
        string $date,
        string $matchdayType = 'regular',
        bool $isPlayed = false
    ): int {
        $id = $this->id++;
        $this->matchdays[$id] = [
            'id'               => $id,
            'season_id'        => $seasonId,
            'matchday_number' => $matchdayCounter,
            'date'            => $date,
            'matchday_type'     => $matchdayType,
        ];

        return $id;
    }

    public function update(int $id, string $date, string $matchdayType): void
    {
        if (isset($this->matchdays[$id])) {
            $this->matchdays[$id]['date'] = $date;
            $this->matchdays[$id]['matchday_type'] = $matchdayType;
        }
    }

    public function delete(int $id): void
    {
        unset($this->matchdays[$id]);
    }

    public function find(int $id): ?array
    {
        return $this->matchdays[$id] ?? null;
    }

    public function findAllForSeason(int $seasonId): array
    {
        return array_values(array_filter(
            $this->matchdays,
            static fn (array $s): bool => (int) $s['season_id'] === $seasonId,
        ));
    }
}

/** Null object for the season configuration (irrelevant in the controller test). */
final class NullSeasonConfigStore implements SeasonConfigStore
{
    public function save(
        int $seasonId,
        int $laneCount,
        string $entryFee,
        string $blindFee,
        int $maxHandicapValue,
        ?string $handicapFormula,
        int $targetScore,
        int $ghostScore = \Bowlingliga\Application\SeasonConfiguration::DEFAULT_GHOST_SCORE,
        int $previousSeasonMatchdays = \Bowlingliga\Application\SeasonConfiguration::DEFAULT_PREVIOUS_SEASON_MATCHDAYS,
        int $positiondayInterval = \Bowlingliga\Application\SeasonConfiguration::DEFAULT_POSITIONDAY_INTERVAL,
        ?\Bowlingliga\Domain\Points\PointsRules $points = null,
    ): void {
    }

    public function delete(int $seasonId): void
    {
    }

    public function find(int $seasonId): ?array
    {
        return null;
    }
}

/** In-memory read port for the master data overview. */
final class InMemoryMasterDataReader implements MasterDataReader
{
    public function __construct(
        private readonly InMemoryPlayerStore $player,
        private readonly InMemoryTeamSeasonStore $teamSeasons,
        private readonly InMemorySeasonStore $seasons,
        private readonly InMemoryMatchdayStore $matchdays,
        private readonly InMemoryAccountsRepo $accounts,
    ) {
    }

    public function allPlayers(): array
    {
        return $this->player->all();
    }

    public function seasonTeams(int $seasonId): array
    {
        return $this->teamSeasons->findAllForSeason($seasonId);
    }

    public function allSeasons(): array
    {
        return $this->seasons->findAllSeasons();
    }

    public function seasonMatchdays(int $seasonId): array
    {
        return $this->matchdays->findAllForSeason($seasonId);
    }

    public function allAccounts(): array
    {
        return $this->accounts->findAllAccounts();
    }

    public function seasonRoster(int $seasonId): array
    {
        return [];
    }
}

/**
 * In-memory admin account repository (extends the non-final
 * {@see AdminAccountRepository}; base constructor deliberately not called).
 */
final class InMemoryAccountsRepo extends AdminAccountRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $accounts = [];
    private int $id = 1;

    /** @phpstan-ignore-next-line: no DB access in the test. */
    public function __construct()
    {
    }

    public function create(string $username, string $passwordHash, ?string $totpSecretEncrypted = null): int
    {
        $id = $this->id++;
        $this->accounts[$id] = [
            'id'                         => $id,
            'username'               => $username,
            'password_hash'              => $passwordHash,
            'totp_secret_encrypted' => $totpSecretEncrypted,
            'locked_until'               => null,
            'failed_attempts'               => 0,
            'session_version' => 1,
        ];

        return $id;
    }

    public function delete(int $id): void
    {
        unset($this->accounts[$id]);
    }

    public function find(int $id): ?array
    {
        return $this->accounts[$id] ?? null;
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->find($id);
    }

    public function findByUsernameForUpdate(string $username): ?array
    {
        return $this->findByUsername($username);
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->accounts as $account) {
            if ($account['username'] === $username) {
                return $account;
            }
        }

        return null;
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    public function countItems(): int
    {
        return count($this->accounts);
    }

    public function findAllAccounts(): array
    {
        return array_values($this->accounts);
    }
}
