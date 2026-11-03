<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\ResultEntryException;
use Bowlingliga\Application\ResultEntry\ScoreSheetTeamRawData;
use Bowlingliga\Application\ResultEntry\VirtualScoreSheetService;
use Bowlingliga\Domain\Ocr\RecognizedGame;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see VirtualScoreSheetService} (task 24.1).
 *
 * Validates: R21.2, R30.5, R30.6, R30.7, R30.8, R30.9, R30.10, R30.17
 *
 * Checks the uniform structure of both teams – one row per stored row with 3
 * fields each, also for more than 4 players (R30.5, R21.2) –, the empty entry
 * (R30.6), resuming a draft including a substituted player (R30.17), the OCR
 * prefill with blind flag (R30.8/R30.10) and the review need including the
 * overall note (R30.9). The read port is an in-memory fake.
 */
final class VirtualScoreSheetServiceTest extends TestCase
{
    /**
     * @param array<string|null, array<int, array{net: int, is_blind: bool}>> $rows name → results by game
     */
    private function team(int $id, string $name, array $rows = []): ScoreSheetTeamRawData
    {
        $list = [];
        $playerId = $id * 10;
        foreach ($rows as $player => $results) {
            $list[] = ['player_id' => ++$playerId, 'name' => (string) $player, 'is_blind' => false, 'results' => $results];
        }

        return new ScoreSheetTeamRawData($id, $name, $list);
    }

    /**
     * Four players without results.
     *
     * @return array<string, array<int, array{net: int, is_blind: bool}>>
     */
    private function fourPlayers(): array
    {
        return ['Anna' => [], 'Bernd' => [], 'Clara' => [], 'Dirk' => []];
    }

    public function testEveryStoredRowHasThreeFieldsAlsoWithFivePlayers(): void
    {
        $reader = new FixedScoreSheetReader([
            $this->team(10, 'Alpha', $this->fourPlayers() + ['Eva' => []]),
            $this->team(20, 'Beta', $this->fourPlayers()),
        ]);

        $scoreSheet = (new VirtualScoreSheetService($reader))->openVirtualScoreSheet(1);

        self::assertCount(5, $scoreSheet->home->rows, 'A substitute player gets a row of their own (R21.2).');
        self::assertCount(4, $scoreSheet->away->rows);
        foreach ($scoreSheet->teams() as $team) {
            foreach ($team->rows as $row) {
                self::assertCount(3, $row->fields, 'Every row has exactly 3 game fields (R30.5).');
            }
        }
    }

    public function testWithoutDataFieldsAreEmpty(): void
    {
        $reader = new FixedScoreSheetReader([
            $this->team(10, 'Alpha', $this->fourPlayers()),
            $this->team(20, 'Beta'),
        ]);

        $scoreSheet = (new VirtualScoreSheetService($reader))->openVirtualScoreSheet(1);

        self::assertNull($scoreSheet->home->rows[0]->fields[0]->net, 'Without data the fields are empty (R30.6).');
        self::assertSame([], $scoreSheet->away->rows, 'Without stored rows the team has none yet.');
        self::assertFalse($scoreSheet->reworkRequired);
    }

    public function testDraftWithSubstitutionIsRestored(): void
    {
        $reader = new FixedScoreSheetReader([
            $this->team(10, 'Alpha', [
                'Anna' => [1 => ['net' => 178, 'is_blind' => false]],
                'Eva'  => [2 => ['net' => 160, 'is_blind' => false], 3 => ['net' => 171, 'is_blind' => false]],
            ]),
            $this->team(20, 'Beta'),
        ]);

        $scoreSheet = (new VirtualScoreSheetService($reader))->openVirtualScoreSheet(1);

        [$anna, $eva] = $scoreSheet->home->rows;
        self::assertSame('Anna', $anna->playerLabel);
        self::assertSame(101, $anna->playerId);
        self::assertSame(178, $anna->fields[0]->net, 'A saved draft is restored (R30.17).');
        self::assertNull($anna->fields[1]->net, 'Games after the substitution stay empty.');
        self::assertNull($eva->fields[0]->net);
        self::assertSame(171, $eva->fields[2]->net);
    }

    public function testOcrPrefillWithBlindMarking(): void
    {
        $reader = new FixedScoreSheetReader([
            $this->team(10, 'Alpha', $this->fourPlayers()),
            $this->team(20, 'Beta', $this->fourPlayers()),
        ]);

        $ocrHome = [
            new RecognizedGame(1, 1, 150, true, false),
        ];

        $scoreSheet = (new VirtualScoreSheetService($reader))->openVirtualScoreSheet(1, $ocrHome);

        $field = $scoreSheet->home->rows[0]->fields[0];
        self::assertSame(150, $field->net, 'The recognised net value is taken over as sheet value (R30.8/R30.10).');
        self::assertTrue($field->isBlind, 'The recognised blind mark is taken over (R30.8).');
    }

    public function testOcrFieldNeedingReviewTriggersRework(): void
    {
        $reader = new FixedScoreSheetReader([
            $this->team(10, 'Alpha', $this->fourPlayers()),
            $this->team(20, 'Beta', $this->fourPlayers()),
        ]);

        $ocrHome = [
            new RecognizedGame(2, 3, null, false, true),
        ];

        $scoreSheet = (new VirtualScoreSheetService($reader))->openVirtualScoreSheet(1, $ocrHome);

        self::assertTrue($scoreSheet->reworkRequired, 'A field needing review triggers the overall notice (R30.9).');
        self::assertTrue($scoreSheet->home->rows[1]->fields[2]->needsReview);
    }

    public function testNonExistentFixtureThrowsException(): void
    {
        $reader = new FixedScoreSheetReader(null);

        $this->expectException(ResultEntryException::class);
        (new VirtualScoreSheetService($reader))->openVirtualScoreSheet(999);
    }
}
