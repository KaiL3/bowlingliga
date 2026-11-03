<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Import;

use Bowlingliga\Application\Import\PlayerCsvParser;
use Bowlingliga\Application\Import\PlayerImport;
use Bowlingliga\Application\Import\PlayerImportException;
use Bowlingliga\Application\Import\PlayerImportReader;
use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\PreviousSeasonRecordManagement;
use Bowlingliga\Application\TeamManagement;
use Bowlingliga\Application\Transaction;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\GermanText;
use Bowlingliga\Tests\Unit\Application\InMemoryPlayerRepository;
use Bowlingliga\Tests\Unit\Application\InMemoryPreviousSeasonRecord;
use Bowlingliga\Tests\Unit\Application\InMemoryRosterRepository;
use Bowlingliga\Tests\Unit\Application\InMemorySeasonReader;
use Bowlingliga\Tests\Unit\Application\InMemoryTeamRepository;
use Bowlingliga\Tests\Unit\Application\InMemoryTeamSeasonRepository;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the player import: check, preview and import with the
 * in-memory ports.
 */
final class PlayerImportTest extends TestCase
{
    private const HEADER = "Team;Name;Geschlecht;Vorjahr Schnitt;Vorjahr Spiele\n";

    private InMemoryPlayerRepository $players;
    private InMemoryRosterRepository $roster;
    private InMemoryTeamSeasonRepository $teamSeasons;
    private InMemoryPreviousSeasonRecord $records;
    private SwitchableWriteAccess $access;
    private PlayerImport $import;

    protected function setUp(): void
    {
        $this->roster = new InMemoryRosterRepository();
        $this->players = new InMemoryPlayerRepository($this->roster);
        $this->teamSeasons = new InMemoryTeamSeasonRepository();
        $this->records = new InMemoryPreviousSeasonRecord();
        $this->access = new SwitchableWriteAccess(true);
        $seasons = new InMemorySeasonReader();
        $seasons->stash(1, '2099-09-01');
        $validator = new Validator();

        $teams = new TeamManagement(new InMemoryTeamRepository(), $this->teamSeasons, $seasons, $validator, $this->access);
        $this->teamSeasons->create(100, 1, 'Pin Busters');
        $this->teamSeasons->createGhostTeam(101, 1, 'Geisterteam');

        $this->import = new PlayerImport(
            new PlayerCsvParser(),
            new FixedPlayerImportReader($this->players, $this->teamSeasons, [1]),
            $this->records,
            new PlayerManagement($this->players, $this->roster, $validator, $this->access),
            $teams,
            new PreviousSeasonRecordManagement($this->records, $this->records, $validator, $this->access),
            new ImmediateTransaction(),
            $validator,
            $this->access,
        );
    }

    public function testPreviewListsNewAndExistingTeams(): void
    {
        $plan = $this->import->plan(1, self::HEADER . "pin busters;Kurt;m;143,64;99\nJolly Rollers;Alma;w;;\nJolly Rollers;Olaf;m;;\n");

        self::assertTrue($plan->isValid(), implode(' ', GermanText::all($plan->errors)));
        self::assertSame(['Jolly Rollers'], $plan->newTeams);
        self::assertSame(['Pin Busters'], $plan->existingTeams, 'Same team name regardless of upper/lower case.');
        self::assertTrue($plan->isNewTeam('jolly rollers'));
        self::assertSame(1, $plan->rowsWithPreviousSeason());
        self::assertSame(0, $this->players->quantity(), 'The preview saves nothing.');
    }

    public function testImportCreatesTeamsPlayersRosterAndPreviousSeason(): void
    {
        $result = $this->import->import(1, self::HEADER . "Pin Busters;Kurt;m;143,64;99\nJolly Rollers;Alma;w;;\n");

        self::assertSame(['players' => 2, 'new_teams' => 1, 'records' => 1], $result);
        self::assertSame(['id' => 1, 'name' => 'Kurt', 'gender' => 'male'], $this->players->find(1));
        self::assertSame([1], $this->roster->findPlayerIdsForTeamSeason(1), 'Kurt im vorhandenen Team.');
        self::assertSame('Jolly Rollers', $this->teamSeasons->find(3)['team_name']);
        self::assertSame([2], $this->roster->findPlayerIdsForTeamSeason(3));
        self::assertSame(['game_count' => 99, 'net_pins_total' => 14220], $this->records->find(1, 1), 'Record of the import season (R29.11).');
        self::assertNull($this->records->find(1, 2));
    }

    public function testSameNamesAbortTheImport(): void
    {
        $this->players->create('Conrad', 'male');

        $plan = $this->import->plan(1, self::HEADER . "Pin Busters;Kurt;m;;\nJolly Rollers;kurt;m;;\nPin Busters;Conrad;m;;\n");

        self::assertFalse($plan->isValid());
        self::assertSame([
            'Zeilen 2, 3: Der Name „Kurt“ kommt mehrfach vor. Bitte eindeutig machen, z. B. mit Nachnamen.',
            'Zeile 4: Einen Spieler „Conrad“ gibt es schon.',
        ], GermanText::all($plan->errors));

        $this->expectException(PlayerImportException::class);
        $this->import->import(1, self::HEADER . "Pin Busters;Kurt;m;;\nJolly Rollers;kurt;m;;\n");
    }

    public function testNothingIsSavedIfOneLineIsInvalid(): void
    {
        try {
            $this->import->import(1, self::HEADER . "Pin Busters;Kurt;m;;\nPin Busters;Anna;x;;\n");
            self::fail('A faulty file must be rejected.');
        } catch (PlayerImportException $e) {
            self::assertSame(['Zeile 3: Das Geschlecht muss „m“ oder „w“ sein, nicht „x“.'], GermanText::all($e->errors));
        }

        self::assertSame(0, $this->players->quantity());
    }

    public function testGhostTeamAndUnknownSeasonAreRejected(): void
    {
        self::assertSame(
            ['Zeile 2: In das Geisterteam „Geisterteam“ kann nicht importiert werden.'],
            GermanText::all($this->import->plan(1, self::HEADER . "Geisterteam;Kurt;m;;\n")->errors)
        );
        self::assertSame(['Die gewählte Saison gibt es nicht.'], GermanText::all($this->import->plan(9, self::HEADER . "A;Kurt;m;;\n")->errors));
        self::assertSame(['Die Datei enthält keine Spielerzeilen.'], GermanText::all($this->import->plan(1, self::HEADER)->errors));
    }

    public function testPreviousSeasonOnlyBeforeTheSeasonStarts(): void
    {
        $this->records->startedSeasons = [1];

        $errors = $this->import->plan(1, self::HEADER . "Pin Busters;Kurt;m;143,64;99\n")->errors;

        self::assertCount(1, $errors);
        self::assertStringContainsString('Bitte die Spalten „Vorjahr Schnitt“ und „Vorjahr Spiele“ leer lassen.', GermanText::of($errors[0]));
        self::assertTrue($this->import->plan(1, self::HEADER . "Pin Busters;Kurt;m;;\n")->isValid(), 'Without a previous season the import continues.');
    }

    public function testImportNeedsWriteAccess(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->import->import(1, self::HEADER . "Pin Busters;Kurt;m;;\n");
    }
}

/** Runs the steps directly; the rollback is covered by the integration test. */
final class ImmediateTransaction implements Transaction
{
    public function perform(callable $steps): mixed
    {
        return $steps();
    }
}

/** Read port on top of the in-memory repositories. */
final class FixedPlayerImportReader implements PlayerImportReader
{
    /**
     * @param list<int> $seasonIds
     */
    public function __construct(
        private readonly InMemoryPlayerRepository $players,
        private readonly InMemoryTeamSeasonRepository $teamSeasons,
        private readonly array $seasonIds,
    ) {
    }

    public function seasonExists(int $seasonId): bool
    {
        return in_array($seasonId, $this->seasonIds, true);
    }

    public function playerNames(): array
    {
        $names = [];
        for ($id = 1; $id <= $this->players->quantity(); $id++) {
            $names[] = (string) ($this->players->find($id)['name'] ?? '');
        }

        return $names;
    }

    public function seasonTeams(int $seasonId): array
    {
        return array_map(
            static fn (array $team): array => ['id' => (int) $team['id'], 'name' => (string) $team['team_name'], 'is_ghost_team' => (int) $team['is_ghost_team'] === 1],
            $this->teamSeasons->findAllForSeason($seasonId)
        );
    }
}
