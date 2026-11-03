<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\ScoreSheetCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see ScoreSheetCalculator} (task 35.3).
 *
 * Validates: R12.9, R12.11, R17.2–R17.9, R27.7, R27.12
 *
 * The reference is an original paper score sheet of the league (not part of the repository)
 * (a real matchday of the league).
 */
final class ScoreSheetCalculatorTest extends TestCase
{
    /**
     * @param list<array{0: int|null, 1: list<int|null>, 2?: bool, 3?: bool}> $lines [HDC, values, blind, first]
     *
     * @return array{rows: array<int, array<string, mixed>>}
     */
    private static function team(array $lines): array
    {
        $rows = [];
        foreach ($lines as $i => $z) {
            $rows[$i + 1] = [
                'hdc'   => $z[0],
                'first_value'  => $z[3] ?? false,
                'blind' => $z[2] ?? false,
                'values' => [1 => $z[1][0], 2 => $z[1][1], 3 => $z[1][2]],
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * @return array<string, array{rows: array<int, array<string, mixed>>}>
     */
    private static function paperSheet(): array
    {
        return [
            'home' => self::team([
                [26, [170, 181, 168]], // home player 1
                [28, [177, 150, 180]], // home player 2
                [18, [173, 190, 174]], // home player 3
                [50, [169, 139, 160]], // home player 4
            ]),
            'away' => self::team([
                [37, [182, 178, 165]],       // away player 1
                [30, [148, 170, 151]],       // away player 2
                [13, [140, 214, 178]],       // away player 3
                [36, [145, 145, 145], true], // away player 4 (blind, X)
            ]),
        ];
    }

    public function testValuesOfThePaperScoreSheet(): void
    {
        $e = (new ScoreSheetCalculator())->calculate(self::paperSheet(), null);

        // Rows: total net/gross.
        self::assertSame(519, $e['home']['rows'][1]['net_total']);
        self::assertSame(597, $e['home']['rows'][1]['gross_total']);
        self::assertSame(618, $e['home']['rows'][4]['gross_total']);
        self::assertSame(543, $e['away']['rows'][4]['gross_total'], 'Blind gross with the HDC of the player used (R27.7).');

        // Sums per game and in total.
        self::assertSame([689, 660, 682], array_column($e['home']['games'], 'net'));
        self::assertSame([122, 122, 122], array_column($e['home']['games'], 'hdc'));
        self::assertSame([811, 782, 804], array_column($e['home']['games'], 'gross'));
        self::assertSame(2031, $e['home']['net_total']);
        self::assertSame(366, $e['home']['hdc_total']);
        self::assertSame(2397, $e['home']['gross_total']);
        self::assertSame([731, 823, 755], array_column($e['away']['games'], 'gross'));
        self::assertSame(2309, $e['away']['gross_total']);

        // Points: home 2/0/2 + 4 bonus = 8; away 0/2/0 + 4 = 6.
        self::assertSame([2, 0, 2], array_column($e['home']['games'], 'points'));
        self::assertSame(4, $e['home']['game_points']);
        self::assertSame(4, $e['home']['bonus']);
        self::assertSame(8, $e['home']['total']);
        self::assertSame([0, 2, 0], array_column($e['away']['games'], 'points'));
        self::assertSame(6, $e['away']['total']);
    }

    public function testIncompleteGamesWithoutSumsAndPoints(): void
    {
        $pages = self::paperSheet();
        $pages['away']['rows'][2]['values'][3] = null;

        $e = (new ScoreSheetCalculator())->calculate($pages, null);

        self::assertNull($e['away']['games'][3]['gross']);
        self::assertNull($e['away']['gross_total']);
        self::assertSame(318, $e['away']['rows'][2]['net_total'], 'The row total covers the games entered.');
        self::assertSame(178, $e['away']['rows'][2]['gross'][1], 'Single gross values are calculated anyway.');
        self::assertNull($e['home']['games'][3]['points'], 'Game 3 can only be scored once both teams are complete.');
        self::assertSame(2, $e['home']['games'][1]['points']);
        self::assertNull($e['home']['total']);
        self::assertSame(4, $e['home']['bonus'], 'The own bonus depends only on the own gross.');
    }

    public function testTieGivesOnePointEach(): void
    {
        $equal = self::team([[0, [150, 150, 150]], [0, [150, 150, 150]], [0, [150, 150, 150]], [0, [150, 150, 150]]]);

        $e = (new ScoreSheetCalculator())->calculate(['home' => $equal, 'away' => $equal], null);

        self::assertSame([1, 1, 1], array_column($e['home']['games'], 'points'));
        self::assertSame(1, $e['home']['bonus'], 'Bis 2100 Pins: 1 Bonuspunkt.');
        self::assertSame(4, $e['home']['total']);
    }

    public function testFirstMatchdayHandicapFromTheGamesOfTheEvening(): void
    {
        $pages = self::paperSheet();
        // Without an average: 150/160/170 → average 160 → (190 − 160) × 0.8 = 24.
        $pages['home']['rows'][1] = ['hdc' => null, 'first_value' => true, 'blind' => false, 'values' => [1 => 150, 2 => 160, 3 => 170]];

        $e = (new ScoreSheetCalculator())->calculate($pages, ['factor' => 0.8, 'target_score' => 190, 'max' => 64]);

        self::assertSame(24, $e['home']['rows'][1]['hdc']);
        self::assertSame(174, $e['home']['rows'][1]['gross'][1]);
    }

    public function testFirstMatchdayHandicapOnlyFromRealGames(): void
    {
        $pages = self::paperSheet();
        // Game 3 blind (R27.5, R27.13): average (150 + 160) / 2 = 155 → (190 − 155) × 0.8 = 28.
        $pages['home']['rows'][1] = [
            'hdc' => null, 'first_value' => true, 'blind' => false,
            'values' => [1 => 150, 2 => 160, 3 => 170], 'blind_games' => [1 => false, 2 => false, 3 => true],
        ];

        $e = (new ScoreSheetCalculator())->calculate($pages, ['factor' => 0.8, 'target_score' => 190, 'max' => 64]);

        self::assertSame(28, $e['home']['rows'][1]['hdc']);
        self::assertSame(198, $e['home']['rows'][1]['gross'][3], 'The blind game counts with HDC for the team.');
    }

    public function testWithoutHandicapGrossEqualsNet(): void
    {
        $pages = self::paperSheet();
        $pages['home']['rows'][1]['hdc'] = null;

        $e = (new ScoreSheetCalculator())->calculate($pages, null);

        self::assertNull($e['home']['rows'][1]['hdc']);
        self::assertSame(519, $e['home']['rows'][1]['gross_total']);
    }

    /**
     * Player 1 of the home team is replaced by a fifth player after game 1
     * (R21.2, R27.17): per game still 4 results, the sums are complete.
     */
    public function testSubstitutedPlayerCountsOnlyForTheirGames(): void
    {
        $pages = self::paperSheet();
        $pages['home']['rows'][1]['values'] = [1 => 170, 2 => null, 3 => null];
        $pages['home']['rows'][5] = ['hdc' => 20, 'first_value' => false, 'blind' => false, 'values' => [1 => null, 2 => 181, 3 => 168]];

        $e = (new ScoreSheetCalculator())->calculate($pages, null);

        self::assertSame(170, $e['home']['rows'][1]['net_total']);
        self::assertSame(196, $e['home']['rows'][1]['gross_total']);
        self::assertSame(389, $e['home']['rows'][5]['gross_total']);
        self::assertSame([4, 4, 4], array_column($e['home']['games'], 'count'));
        self::assertSame([811, 776, 798], array_column($e['home']['games'], 'gross'), 'From game 2 on the handicap of the substitute counts.');
        self::assertSame(2385, $e['home']['gross_total']);
    }

    public function testFiveResultsInOneGameGiveNoSum(): void
    {
        $pages = self::paperSheet();
        $pages['home']['rows'][5] = ['hdc' => 0, 'first_value' => false, 'blind' => false, 'values' => [1 => 150, 2 => null, 3 => null]];

        $e = (new ScoreSheetCalculator())->calculate($pages, null);

        self::assertSame(5, $e['home']['games'][1]['count']);
        self::assertNull($e['home']['games'][1]['gross'], 'More than 4 results: the game cannot be scored.');
        self::assertNull($e['home']['total']);
    }
}