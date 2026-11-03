<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\TeamEntryController;
use PHPUnit\Framework\TestCase;

/**
 * Actions of the guided team entry (R37): which step follows, which notice is
 * passed on, and that a database error does not show its own message
 * (SQLSTATE, table and key names) – the team page needs no login.
 */
final class TeamEntryControllerTest extends TestCase
{
    private TeamEntryFixture $fixture;
    private string $errorLog;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->fixture = new TeamEntryFixture();

        // The controller logs database errors; keep the test output clean.
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'team-entry-log');
        $this->previousErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    public function testWeiterSavesTheGameChecksItAndGoesOn(): void
    {
        $code = $this->fixture->code('home');
        $input = ['code' => $code, 'game' => '1', 'next' => '2', 'lineup' => [11 => 'plays', 12 => 'plays'], 'nets' => [11 => '170', 12 => '']];

        $result = $this->fixture->controller->action('save', $input);
        self::assertSame(1, $result['step'], 'An incomplete game stays.');
        self::assertStringContainsString('fehlt das Ergebnis', (string) $result['error']);
        self::assertSame(170, $this->fixture->entry->open($code)->participation(10)[11][1]['net'], 'What was entered is saved anyway.');

        $input['nets'][12] = '160';
        self::assertSame(['code' => $code, 'step' => 2, 'notice' => null, 'error' => null], $this->fixture->controller->action('save', $input));

        // Going back does not check.
        $back = $this->fixture->controller->action('save', ['code' => $code, 'game' => '2', 'next' => '1']);
        self::assertSame(1, $back['step']);
        self::assertNull($back['error']);
    }

    public function testSendingAndConfirmingLeadToTheConfirmationStep(): void
    {
        $this->fixture->enterAll('home', 170);
        $this->fixture->enterAll('away', 160);

        self::assertSame(
            [TeamEntryController::STEP_STATUS, 'sent', null],
            $this->summary($this->fixture->controller->action('submit', ['code' => $this->fixture->code('home')])),
        );
        self::assertSame(
            [TeamEntryController::STEP_STATUS, 'confirmed', null],
            $this->summary($this->fixture->controller->action('confirm', ['code' => $this->fixture->code('away')])),
        );
        $this->fixture->controller->action('submit', ['code' => $this->fixture->code('away')]);
        self::assertSame(
            [TeamEntryController::STEP_STATUS, 'final', null],
            $this->summary($this->fixture->controller->action('confirm', ['code' => $this->fixture->code('home')])),
        );
        self::assertArrayHasKey(TeamEntryFixture::FIXTURE, $this->fixture->results->saved);
        foreach (['sent', 'confirmed', 'final'] as $notice) {
            self::assertArrayHasKey($notice, TeamEntryController::NOTICES);
        }
    }

    public function testStepUrlCarriesCodeStepAndNotice(): void
    {
        self::assertSame('/entry?code=a%2Bb&step=5&notice=final', TeamEntryController::stepUrl('a+b', 5, 'final'));
    }

    public function testDatabaseErrorShowsAGeneralMessageAndGoesToTheLog(): void
    {
        $this->fixture->store->lockFailure = new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '100-11-1' for key 'uq_team_entry_game'"
        );

        $result = $this->fixture->controller->action('submit', ['code' => $this->fixture->code('home')]);

        self::assertSame('Die Eingabe konnte gerade nicht gespeichert werden. Bitte noch einmal versuchen.', $result['error']);
        self::assertNull($result['notice']);
        self::assertSame(TeamEntryController::STEP_OVERVIEW, $result['step']);
        self::assertStringContainsString('uq_team_entry_game', (string) file_get_contents($this->errorLog), 'The details go to the log.');
    }

    public function testDomainErrorKeepsItsMessage(): void
    {
        // Not sent yet: withdrawing is refused with a message for the team.
        $result = $this->fixture->controller->action('withdraw', ['code' => $this->fixture->code('home')]);

        self::assertStringContainsString('noch nicht abgeschickt', (string) $result['error']);
    }

    /**
     * @param array{code: string, step: int|null, notice: string|null, error: string|null} $result
     *
     * @return array{0: int|null, 1: string|null, 2: string|null}
     */
    private function summary(array $result): array
    {
        return [$result['step'], $result['notice'], $result['error']];
    }
}
