<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\EnteredGame;
use Bowlingliga\Application\ResultEntry\ResultEntryException;
use Bowlingliga\Application\ResultEntry\TeamSheetRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see TeamSheetRules}.
 *
 * Validates: R21.2, R21.4, R27.2, R27.4, R27.5, R27.17, R30.4
 *
 * Players are substituted between games only; at most 4 play at the same
 * time, a substituted player does not come back; a player serving as blind
 * player has a row with only blind games.
 */
final class TeamSheetRulesTest extends TestCase
{
    private const TEAM = 10;

    private TeamSheetRules $rules;

    protected function setUp(): void
    {
        $this->rules = new TeamSheetRules();
    }

    /**
     * Games of the players with the given game numbers (150 pins each).
     *
     * @param array<int, list<int>> $players player id → game numbers
     * @param array<int, list<int>> $blind   player id → game numbers marked blind
     *
     * @return list<EnteredGame>
     */
    private function games(array $players, array $blind = []): array
    {
        $games = [];
        foreach ($players as $playerId => $numbers) {
            foreach ($numbers as $num) {
                $games[] = EnteredGame::player(self::TEAM, $playerId, $num, 150, in_array($num, $blind[$playerId] ?? [], true));
            }
        }

        return $games;
    }

    public function testRegularEveningWithFourPlayersIsComplete(): void
    {
        $this->rules->checkComplete($this->games([1 => [1, 2, 3], 2 => [1, 2, 3], 3 => [1, 2, 3], 4 => [1, 2, 3]]), 'Adler');

        $this->addToAssertionCount(1);
    }

    public function testSixPlayersWithTwoSubstitutionsAreComplete(): void
    {
        // Player 1 is replaced after game 1 by player 5, player 2 after game 2 by player 6.
        $this->rules->checkComplete($this->games([
            1 => [1], 5 => [2, 3], 2 => [1, 2], 6 => [3], 3 => [1, 2, 3], 4 => [1, 2, 3],
        ]), 'Adler');

        $this->addToAssertionCount(1);
    }

    public function testInjuredPlayerWithoutSubstituteHasOwnBlindGames(): void
    {
        $this->rules->checkComplete($this->games(
            [1 => [1, 2, 3], 2 => [1, 2, 3], 3 => [1, 2, 3], 4 => [1, 2, 3]],
            [1 => [2, 3]],
        ), 'Adler');

        $this->addToAssertionCount(1);
    }

    public function testBlindPlayersFillMissingPlayers(): void
    {
        $this->rules->checkComplete($this->games(
            [1 => [1, 2, 3], 2 => [1, 2, 3], 5 => [1, 2, 3], 6 => [1, 2, 3]],
            [5 => [1, 2, 3], 6 => [1, 2, 3]],
        ), 'Adler');

        $this->addToAssertionCount(1);
    }

    public function testMoreThanFourAtTheSameTimeIsRejected(): void
    {
        $this->expectException(ResultEntryException::class);
        $this->expectExceptionMessage('error.result.too_many_in_game');

        $this->rules->checkEntered($this->games([1 => [2], 2 => [2], 3 => [2], 4 => [2], 5 => [2]]), 'Adler');
    }

    public function testMissingResultIsRejectedOnConfirmation(): void
    {
        $this->expectException(ResultEntryException::class);
        $this->expectExceptionMessage('error.result.game_incomplete');

        $this->rules->checkComplete($this->games([1 => [1, 2], 2 => [1, 2, 3], 3 => [1, 2, 3], 4 => [1, 2, 3]]), 'Adler');
    }

    public function testReentryIsRejected(): void
    {
        $this->expectException(ResultEntryException::class);
        $this->expectExceptionMessage('error.result.reentry');

        $this->rules->checkComplete(
            $this->games([1 => [1, 3], 5 => [2], 2 => [1, 2, 3], 3 => [1, 2, 3], 4 => [1, 2, 3]]),
            'Adler',
            [1 => 'Anna'],
        );
    }

    public function testBlindGameBetweenOwnGamesIsAReentry(): void
    {
        $this->expectException(ResultEntryException::class);
        $this->expectExceptionMessage('error.result.reentry');

        $this->rules->checkComplete($this->games(
            [1 => [1, 2, 3], 2 => [1, 2, 3], 3 => [1, 2, 3], 4 => [1, 2, 3]],
            [1 => [2]],
        ), 'Adler');
    }

    public function testGameWithoutRealPlayerIsRejected(): void
    {
        // Games 1 and 2: two players and two blind players. Game 3: player 2
        // only blind (injured) and three blind players – nobody plays for real.
        $games = $this->games(
            [1 => [1, 2], 2 => [1, 2, 3], 5 => [1, 2, 3], 6 => [1, 2, 3], 7 => [3]],
            [2 => [3], 5 => [1, 2, 3], 6 => [1, 2, 3], 7 => [3]],
        );

        $this->expectException(ResultEntryException::class);
        $this->expectExceptionMessage('error.result.no_real_player');

        $this->rules->checkComplete($games, 'Adler');
    }
}
