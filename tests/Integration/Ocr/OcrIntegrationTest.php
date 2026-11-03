<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Ocr;

use Bowlingliga\Application\ResultEntry\OriginalScoreSheetStorage;
use Bowlingliga\Domain\Ocr\UploadedFile;
use Bowlingliga\Domain\Ocr\OcrException;
use Bowlingliga\Domain\Ocr\OcrModule;
use Bowlingliga\Domain\Ocr\AssignmentResult;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;
use Bowlingliga\Persistence\MatchdayAssignmentRepository;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Tests\Unit\Domain\Ocr\FixedTextRecognizer;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the OCR assignment and of keeping the original score
 * sheet against a local MySQL/MariaDB test database (task 25.3).
 *
 * Validates: R3.3, R3.4, R3.5, R3.15, R3.17, R3.18
 *
 * - R3.3: if exactly one fixture of the matchday belonging to the
 *   recognized date matches the recognized team names, the assignment is
 *   unique.
 * - R3.4: if the recognized team names do not allow a unique
 *   assignment, the administrator is offered the fixtures of the day for
 *   selection.
 * - R3.5: if no matchday exists for the recognized date, the
 *   assignment is suspended.
 * - R3.15, R3.17: the uploaded original score sheet is stored
 *   unchanged and kept regardless of the OCR result – also when the
 *   recognition failed or was corrected manually.
 * - R3.18: the parts of a split lot draw score sheet are merged
 *   into one complete record.
 *
 * Unlike the pure {@see \Bowlingliga\Tests\Unit\Domain\Ocr\OcrModuleTest}
 * (which reproduces assignment and persistence in memory) this test uses the
 * real {@see MatchdayAssignmentRepository} and the real
 * {@see OriginalScoreSheetRepository} against the database. The OCR engine is
 * still replaced by a {@see FixedTextRecognizer}, so that the test runs
 * deterministically without Tesseract.
 *
 * The test needs a reachable local MySQL/MariaDB test database and the PDO
 * MySQL driver. If either is missing, the test is skipped in a controlled way
 * (markTestSkipped) instead of failing. The connection parameters are read
 * only from environment variables (no credentials in the source code):
 *
 *   BOWLINGLIGA_TEST_DB_HOST     (default: 127.0.0.1)
 *   BOWLINGLIGA_TEST_DB_PORT     (default: 3306)
 *   BOWLINGLIGA_TEST_DB_NAME     (default: bowlingliga_test)
 *   BOWLINGLIGA_TEST_DB_USER     (default: root)
 *   BOWLINGLIGA_TEST_DB_PASSWORD (default: empty)
 *   BOWLINGLIGA_TEST_DB_CHARSET  (default: utf8mb4)
 *
 * The tables involved are created per test and removed afterwards, so that
 * the test is repeatable and runs without migration prerequisites.
 */
final class OcrIntegrationTest extends TestCase
{
    private Database $database;
    private PDO $pdo;
    private MatchdayAssignmentRepository $assignment;
    private OriginalScoreSheetRepository $scoreSheet;

    protected function setUp(): void
    {
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available; integration test skipped.');
        }

        $this->database = new Database($this->testConfiguration());

        try {
            $this->pdo = $this->database->pdo();
            $this->pdo->query('SELECT 1');
        } catch (Throwable $throwable) {
            self::markTestSkipped('No reachable MySQL test database: ' . $throwable->getMessage());
        }

        $this->createTables();

        $this->assignment   = new MatchdayAssignmentRepository($this->database);
        $this->scoreSheet = new OriginalScoreSheetRepository($this->database, new \Bowlingliga\Tests\Unit\Application\InMemoryBlobStore());
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->dropTables();
        }
    }

    /**
     * R3.3: if exactly one fixture of the day matches the recognized team
     * names, the assignment is unique – checked against the real database.
     */
    public function testUniqueAssignmentAgainstDatabase(): void
    {
        [, $fixtureAlphaBeta] = $this->createMatchdayWithTwoFixtures('2026-01-15');

        $rawText = "DATUM: 15.01.2026\nTEAM: Alpha\nTEAM: Beta\n1 1 178 X";
        $module   = new OcrModule(new FixedTextRecognizer($rawText));

        $data    = $module->recognizeScoreSheet($this->file());
        $result = $module->assign($data, $this->assignment);

        self::assertSame(AssignmentResult::UNIQUE, $result->status);
        self::assertSame($fixtureAlphaBeta, $result->fixtureId);
    }

    /**
     * R3.4: if the recognized team names do not allow a unique assignment, all
     * fixtures of the day are offered for selection.
     */
    public function testAmbiguousAssignmentOffersCandidates(): void
    {
        [$matchdayId, $fixtureAlphaBeta, $fixtureGammaDelta] =
            $this->createMatchdayWithTwoFixtures('2026-01-15');

        // Recognised team names match no fixture -> manual choice (R3.4).
        $data    = new \Bowlingliga\Domain\Ocr\RecognizedGameData('2026-01-15', ['Fremd', 'Unbekannt'], []);
        $result = (new OcrModule(new FixedTextRecognizer('')))->assign($data, $this->assignment);

        self::assertSame(AssignmentResult::AMBIGUOUS, $result->status);
        self::assertSame($matchdayId, $result->matchdayId);
        self::assertEqualsCanonicalizing(
            [$fixtureAlphaBeta, $fixtureGammaDelta],
            $result->candidateFixtureIds
        );
    }

    /**
     * R3.5: if no matchday exists for the recognized date, the assignment is
     * suspended.
     */
    public function testNoMatchdayForDate(): void
    {
        $this->createMatchdayWithTwoFixtures('2026-01-15');

        $data    = new \Bowlingliga\Domain\Ocr\RecognizedGameData('2026-02-20', ['Alpha', 'Beta'], []);
        $result = (new OcrModule(new FixedTextRecognizer('')))->assign($data, $this->assignment);

        self::assertSame(AssignmentResult::NO_MATCHDAY, $result->status);
        self::assertNull($result->fixtureId);
    }

    /**
     * R3.18: the parts of a lot draw score sheet split in the middle are merged
     * into one complete record.
     */
    public function testSplitScoreSheetsAreMerged(): void
    {
        $module = new OcrModule(new FixedTextRecognizer(''));

        $partA = (new OcrModule(new FixedTextRecognizer("DATUM: 15.01.2026\nTEAM: Alpha\nTEAM: Beta\n1 1 178 X")))
            ->recognizeScoreSheet($this->file());
        $partB = (new OcrModule(new FixedTextRecognizer("2 1 165 1\n3 1 150 2")))
            ->recognizeScoreSheet($this->file());

        $merged = $module->mergeShared([$partA, $partB]);

        self::assertSame('2026-01-15', $merged->date);
        self::assertSame(['Alpha', 'Beta'], $merged->teamNames);
        self::assertCount(3, $merged->games, 'Both halves must be merged (R3.18).');
    }

    /**
     * R3.15/R3.17: the original score sheet is stored unchanged and kept even
     * if the OCR recognition fails.
     */
    public function testOriginalIsKeptOnFailedOcr(): void
    {
        [, $fixtureId] = $this->createMatchdayWithTwoFixtures('2026-01-15');

        // OCR fails – storing the original is decoupled from that.
        $module = new OcrModule(new FixedTextRecognizer('', throwsError: true));
        try {
            $module->recognizeScoreSheet($this->file());
            self::fail('A failed OCR recognition must throw an OcrException (R3.14).');
        } catch (OcrException) {
            // expected
        }

        $content = random_bytes(256);
        $file  = new UploadedFile($content, 'zettel.jpg', 'image/jpeg');

        $storage = new OriginalScoreSheetStorage($this->scoreSheet, new AlwaysWriteAccess());
        $id          = $storage->save($fixtureId, $file);

        // The original score sheet is persisted unchanged (R3.15, R3.17).
        $saved = $this->scoreSheet->find($id);
        self::assertNotNull($saved);
        self::assertSame($fixtureId, (int) $saved['fixture_id']);
        self::assertSame('image/jpeg', $saved['mime_type']);
        self::assertSame($content, $saved['file'], 'The binary content must be stored unchanged (R3.15).');
    }

    /**
     * R3.17: even after a manual correction of the recognized values the
     * original score sheet is kept unchanged; the stored binary content equals
     * the upload and not the corrected data.
     */
    public function testOriginalIsKeptOnCorrectedOcr(): void
    {
        [, $fixtureId] = $this->createMatchdayWithTwoFixtures('2026-01-15');

        // Successful recognition needing review, which the admin corrects.
        $module = new OcrModule(new FixedTextRecognizer("DATUM: 15.01.2026\nTEAM: Alpha\nTEAM: Beta\n1 1 - ?"));
        $data = $module->recognizeScoreSheet($this->file());
        self::assertTrue($data->hasValuesNeedingReview(), 'Precondition: the recognition needs review.');

        $content = random_bytes(256);
        $file  = new UploadedFile($content, 'zettel.png', 'image/png');

        $storage = new OriginalScoreSheetStorage($this->scoreSheet, new AlwaysWriteAccess());
        $id          = $storage->save($fixtureId, $file);

        $saved = $this->scoreSheet->find($id);
        self::assertNotNull($saved);
        self::assertSame($content, $saved['file'], 'The upload stays unchanged despite the correction (R3.17).');
    }

    // --- Hilfen ---

    private function file(string $extension = 'jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        return new UploadedFile('x', 'zettel.' . $extension, $mime);
    }

    /**
     * Creates a season, a matchday on the given date and two fixtures
     * (Alpha/Beta and Gamma/Delta).
     *
     * @return array{0: int, 1: int, 2: int} [matchdayId, fixtureAlphaBeta, fixtureGammaDelta]
     */
    private function createMatchdayWithTwoFixtures(string $date): array
    {
        $seasonId = $this->insert(
            'INSERT INTO season (name, start_date, end_date) VALUES (:n, :s, :e)',
            ['n' => 'Testsaison', 's' => '2025-09-01', 'e' => '2026-06-30']
        );

        $matchdayId = $this->insert(
            'INSERT INTO matchday (season_id, matchday_number, date) VALUES (:season, 1, :d)',
            ['season' => $seasonId, 'd' => $date]
        );

        $teamA = $this->createTeamSeason($seasonId, 'Alpha');
        $teamB = $this->createTeamSeason($seasonId, 'Beta');
        $teamG = $this->createTeamSeason($seasonId, 'Gamma');
        $teamD = $this->createTeamSeason($seasonId, 'Delta');

        $alphaBeta = $this->insert(
            'INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id)
             VALUES (:sp, :h, :g)',
            ['sp' => $matchdayId, 'h' => $teamA, 'g' => $teamB]
        );
        $gammaDelta = $this->insert(
            'INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id)
             VALUES (:sp, :h, :g)',
            ['sp' => $matchdayId, 'h' => $teamG, 'g' => $teamD]
        );

        return [$matchdayId, $alphaBeta, $gammaDelta];
    }

    private function createTeamSeason(int $seasonId, string $teamLabel): int
    {
        $teamId = $this->insert('INSERT INTO team () VALUES ()', []);

        return $this->insert(
            'INSERT INTO team_season (team_id, season_id, team_name) VALUES (:t, :s, :n)',
            ['t' => $teamId, 's' => $seasonId, 'n' => $teamLabel]
        );
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private function insert(string $sql, array $parameter): int
    {
        $this->pdo->prepare($sql)->execute($parameter);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTables(): void
    {
        $this->dropTables();

        $this->pdo->exec(
            'CREATE TABLE season (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE team (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE team_season (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                team_id INT UNSIGNED NOT NULL,
                season_id INT UNSIGNED NOT NULL,
                team_name VARCHAR(100) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_oit_team_saison_team FOREIGN KEY (team_id)
                    REFERENCES team (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_oit_team_saison_saison FOREIGN KEY (season_id)
                    REFERENCES season (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE matchday (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                season_id INT UNSIGNED NOT NULL,
                matchday_number INT UNSIGNED NOT NULL,
                date DATE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_oit_spieltag_saison FOREIGN KEY (season_id)
                    REFERENCES season (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE fixture (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                matchday_id INT UNSIGNED NOT NULL,
                home_team_season_id INT UNSIGNED NULL,
                away_team_season_id INT UNSIGNED NULL,
                lane_pair_id INT UNSIGNED NULL,
                completion_status ENUM(\'open\', \'in_progress\', \'complete\') NOT NULL DEFAULT \'open\',
                PRIMARY KEY (id),
                CONSTRAINT fk_oit_teampaarung_spieltag FOREIGN KEY (matchday_id)
                    REFERENCES matchday (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_oit_teampaarung_heim FOREIGN KEY (home_team_season_id)
                    REFERENCES team_season (id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_oit_teampaarung_gast FOREIGN KEY (away_team_season_id)
                    REFERENCES team_season (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE original_score_sheet (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                fixture_id INT UNSIGNED NOT NULL,
                content_hash CHAR(64) CHARACTER SET ascii NOT NULL,
                size_bytes INT UNSIGNED NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_oit_original_teampaarung FOREIGN KEY (fixture_id)
                    REFERENCES fixture (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
    }

    private function dropTables(): void
    {
        // Suspend the FK check briefly, so that the order of the DROPs does not
        // matter (the tables are linked via foreign keys).
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['original_score_sheet', 'fixture', 'matchday', 'team_season', 'team', 'season'] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return array<string, mixed>
     */
    private function testConfiguration(): array
    {
        return [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => self::env('BOWLINGLIGA_TEST_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => 5,
        ];
    }

    private static function env(string $name, string $standard): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $standard : $value;
    }
}

/**
 * Slim write access fake that always allows access. The write protection
 * itself is not the subject of this OCR integration test.
 */
final class AlwaysWriteAccess implements WriteAccess
{
    public function checkWriteAccess(): bool
    {
        return true;
    }
}
