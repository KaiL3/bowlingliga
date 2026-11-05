<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapModule;
use Bowlingliga\Domain\Points\BasePointsConfiguration;
use Bowlingliga\Domain\Points\PointsRules;
use Bowlingliga\Domain\Points\PointsService;

/**
 * Calculates the derived values of the virtual score sheet from the entered
 * net results (task 35.3) – as on the paper score sheet:
 *
 * - gross per game = net + handicap of the row's player (R12.11, R13.3); for
 *   blind games the handicap of the blind player (R27.7, R27.12),
 * - total net/gross per row over the games entered in it – a substituted
 *   player has fewer than three games (R21.2),
 * - net, HDC and gross sum per game once exactly 4 results of the game are
 *   entered, and in total once all three games are complete (R17.2),
 * - game points per game from the gross comparison (default 2/1/0,
 *   R17.3–R17.5), bonus points by the gross scale (R17.6–R17.8) and total
 *   points (R17.9), both by the points rules of the season (R17.11).
 *
 * On a player's first matchday without an average (R12.9) the handicap
 * follows from the average of the games they played themselves that evening.
 *
 * Pure calculation without side effects; `public/assets/js/score-sheet.js`
 * reproduces the same logic for the live display in the browser.
 *
 * Input per side ('home', 'away'):
 *   ['rows' => [key => ['hdc' => ?int, 'first_value' => bool, 'blind' => bool,
 *                       'values' => [1 => ?int, 2 => ?int, 3 => ?int],
 *                       'blind_games' => [1 => bool, 2 => bool, 3 => bool] (optional)]]]
 *
 * `blind` marks a row that is blind as a whole, `blind_games` the games marked
 * blind individually (R27.5).
 */
final class ScoreSheetCalculator
{
    private const GAMES = [1, 2, 3];

    /** Results per team and game on the lanes (R17.2, R21.2). */
    private const PLAYERS_PER_GAME = 4;

    public function __construct(
        private readonly PointsService $points = new PointsService(),
        private readonly HandicapModule $handicaps = new HandicapModule(),
    ) {
    }

    /**
     * @param array<string, array{rows: array<int|string, array{hdc: int|null, first_value: bool, blind: bool, values: array<int, int|null>}>}> $pages
     * @param array{factor: float, target_score: int, max: int}|null $formula
     * @param PointsRules|null $rules Points rules of the season; default 2/1/0 and 2100/100/6.
     *
     * @return array<string, array<string, mixed>>
     */
    public function calculate(array $pages, ?array $formula, ?PointsRules $rules = null): array
    {
        $rules ??= PointsRules::standard();
        $result = [];
        foreach ($pages as $page => $data) {
            $result[$page] = $this->calculateTeam($data['rows'], $formula);
        }

        // Game points per game from the gross comparison of both teams.
        $names = array_keys($result);
        if (count($names) === 2) {
            [$a, $b] = $names;
            foreach (self::GAMES as $num) {
                $grossA = $result[$a]['games'][$num]['gross'];
                $grossB = $result[$b]['games'][$num]['gross'];
                if ($grossA === null || $grossB === null) {
                    continue;
                }
                $result[$a]['games'][$num]['points'] = $this->gamePoints($grossA, $grossB, $rules->base);
                $result[$b]['games'][$num]['points'] = $this->gamePoints($grossB, $grossA, $rules->base);
            }
        }

        foreach ($result as $page => $team) {
            $points = array_column($team['games'], 'points');
            $gamePoints = count(array_filter($points, static fn (?int $p): bool => $p !== null)) === 3
                ? array_sum($points)
                : null;
            $bonus = $team['gross_total'] !== null ? $this->points->bonusPoints($team['gross_total'], $rules->bonus) : null;

            $result[$page]['game_points'] = $gamePoints;
            $result[$page]['bonus']       = $bonus;
            $result[$page]['total']       = $gamePoints !== null && $bonus !== null ? $gamePoints + $bonus : null;
        }

        return $result;
    }

    /**
     * @param array<int|string, array{hdc: int|null, first_value: bool, blind: bool, values: array<int, int|null>}> $rows
     * @param array{factor: float, target_score: int, max: int}|null $formula
     *
     * @return array<string, mixed>
     */
    private function calculateTeam(array $rows, ?array $formula): array
    {
        $perRow = [];
        foreach ($rows as $key => $r) {
            $hdc = $r['hdc'];
            if ($hdc === null && $r['first_value'] && ! $r['blind']) {
                // Only games actually played form the average (R27.13).
                $real = array_filter(
                    $r['values'],
                    static fn (int $num): bool => ! ($r['blind_games'][$num] ?? false),
                    ARRAY_FILTER_USE_KEY,
                );
                $hdc = $this->firstMatchdayHandicap($real, $formula);
            }

            $gross = [];
            foreach (self::GAMES as $num) {
                $net = $r['values'][$num] ?? null;
                $gross[$num] = $net !== null ? $net + ($hdc ?? 0) : null;
            }

            $entered = array_filter($r['values'], static fn (?int $v): bool => $v !== null);
            $perRow[$key] = [
                'hdc'         => $hdc,
                'gross'       => $gross,
                'net_total'   => $entered !== [] ? array_sum($entered) : null,
                'gross_total' => $entered !== [] ? array_sum($entered) + count($entered) * ($hdc ?? 0) : null,
            ];
        }

        $games = [];
        foreach (self::GAMES as $num) {
            $net = $hdc = $gross = 0;
            $count = 0;
            foreach ($rows as $key => $r) {
                $value = $r['values'][$num] ?? null;
                if ($value === null) {
                    continue;
                }
                $count++;
                $net   += $value;
                $hdc   += $perRow[$key]['hdc'] ?? 0;
                $gross += $perRow[$key]['gross'][$num];
            }
            $games[$num] = $count === self::PLAYERS_PER_GAME
                ? ['net' => $net, 'hdc' => $hdc, 'gross' => $gross, 'points' => null]
                : ['net' => null, 'hdc' => null, 'gross' => null, 'points' => null];
            $games[$num]['count'] = $count;
        }

        $all = ! in_array(null, array_column($games, 'gross'), true);

        return [
            'rows'        => $perRow,
            'games'       => $games,
            'net_total'   => $all ? array_sum(array_column($games, 'net')) : null,
            'hdc_total'   => $all ? array_sum(array_column($games, 'hdc')) : null,
            'gross_total' => $all ? array_sum(array_column($games, 'gross')) : null,
        ];
    }

    /**
     * Handicap on the first matchday from the average of the entered games
     * (R12.9).
     *
     * @param array<int, int|null>                                  $values
     * @param array{factor: float, target_score: int, max: int}|null $formula
     */
    private function firstMatchdayHandicap(array $values, ?array $formula): ?int
    {
        $played = array_values(array_filter($values, static fn (?int $w): bool => $w !== null));
        if ($formula === null || $played === []) {
            return null;
        }

        $result = $this->handicaps->calculateHandicap(
            array_sum($played) / count($played),
            $formula['target_score'],
            HandicapFormula::withFactor($formula['factor']),
            $formula['max'],
            true,
        );

        return $result->hasHandicap() ? $result->handicap() : null;
    }

    private function gamePoints(int $own, int $opponent, BasePointsConfiguration $base): int
    {
        return match (true) {
            $own > $opponent   => $base->win,
            $own === $opponent => $base->draw,
            default            => $base->loss,
        };
    }
}
