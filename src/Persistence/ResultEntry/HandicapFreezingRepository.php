<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\ResultEntry;

use Bowlingliga\Application\ResultEntry\HandicapFreezing;
use Bowlingliga\Application\ResultEntry\PlayedGamesReader;
use Bowlingliga\Application\ResultEntry\PlayerAverageReader;
use Bowlingliga\Application\SeasonConfiguration;
use Bowlingliga\Domain\Average\AverageInput;
use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Average\PreviousSeasonRecord;
use Bowlingliga\Domain\Handicap\HandicapFreezeService;
use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapChainRecalculation;
use Bowlingliga\Domain\Handicap\HandicapChainMatchday;
use Bowlingliga\Domain\Handicap\HandicapChainPreview;
use Bowlingliga\Domain\Handicap\HandicapModule;
use Bowlingliga\Domain\Points\PointsRules;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\FrozenHandicapRepository;
use Bowlingliga\Presentation\PlayerStatsReader;

/**
 * Production adapter of the {@see HandicapFreezing} port (task 35.3).
 *
 * Assembles the inputs of the pure domain services from the raw data:
 * - A player's average before a matchday via the {@see AverageModule} from
 *   their net games actually played (without blind/ghost team results,
 *   R11.1, R20.4, R27.13) of the current season and – as long as the state
 *   after the previous matchday still includes it (configurable per season,
 *   default up to matchday 5, so for the handicap up to matchday 6) – of the
 *   season's own previous season record or, without one, of the preceding
 *   season (R11.3–R11.6, R29.2). The average
 *   is passed on unrounded; the handicap module rounds it to whole pins.
 *   Fixtures with status "vollständig" count as "played" (drafts do not).
 * - First matchday (R12.9): if a player has no average before the matchday,
 *   the state at the end of this matchday is used.
 * - Season configuration: target score, maximum handicap and handicap formula
 *   (R12.1–R12.4). The formula is read as identifier ("standard") or in the
 *   notation "HDC = (Zielwert - Durchschnitt) * 0,8"; if it is missing or
 *   unreadable, no handicap is calculated (R12.13).
 *
 * Handicaps already frozen are never overwritten when freezing (R12.12,
 * R35.5); changes are only possible via the confirmed chain recalculation
 * (R12.17–R12.19).
 */
final class HandicapFreezingRepository extends AbstractRepository implements HandicapFreezing, PlayerStatsReader, PlayedGamesReader, PlayerAverageReader
{
    private readonly HandicapModule $handicapModule;
    private readonly HandicapFreezeService $freezeService;
    private readonly AverageModule $averages;

    public function __construct(
        Database $database,
        private readonly FrozenHandicapRepository $frozen,
    ) {
        parent::__construct($database);
        $this->handicapModule   = new HandicapModule();
        $this->freezeService = new HandicapFreezeService($this->handicapModule, $frozen);
        $this->averages   = new AverageModule();
    }

    public function freezeHandicaps(int $matchdayId): void
    {
        $context = $this->matchdayContext($matchdayId);
        if ($context === null) {
            return;
        }

        $config = $this->configuration($context['season_id']);

        foreach ($this->playersOfMatchday($matchdayId) as $playerId) {
            if ($this->frozen->findForMatchdayAndPlayer($matchdayId, $playerId) !== null) {
                continue; // R12.12: eingefrorene Handicaps bleiben stabil.
            }

            [$average, $isFirstMatchday] = $this->averageForHandicap($playerId, $context, $config['previous_season_matchdays']);

            $this->freezeService->freezeHandicap(
                $playerId,
                $matchdayId,
                $average,
                $config['target_score'],
                $config['formula'],
                $config['max_handicap'],
                $isFirstMatchday,
            );
        }
    }

    public function recalculateChain(int $seasonId, int $fromMatchdayCounter): HandicapChainPreview
    {
        $config = $this->configuration($seasonId);

        $matchdays = $this->findAll(
            "SELECT DISTINCT md.id, md.matchday_number
             FROM matchday md
             JOIN fixture fx ON fx.matchday_id = md.id AND fx.completion_status = 'complete'
             WHERE md.season_id = :season_id AND md.matchday_number >= :from_number
             ORDER BY md.matchday_number",
            ['season_id' => $seasonId, 'from_number' => $fromMatchdayCounter]
        );

        $chain = [];
        foreach ($matchdays as $matchday) {
            $context = [
                'matchday_id'      => (int) $matchday['id'],
                'season_id'        => $seasonId,
                'matchday_number' => (int) $matchday['matchday_number'],
            ];

            foreach ($this->playersOfMatchday($context['matchday_id']) as $playerId) {
                [$average, $isFirstMatchday] = $this->averageForHandicap($playerId, $context, $config['previous_season_matchdays']);
                $previous = $this->frozen->findForMatchdayAndPlayer($context['matchday_id'], $playerId);

                $chain[] = new HandicapChainMatchday(
                    $context['matchday_id'],
                    $context['matchday_number'],
                    $playerId,
                    $average,
                    $config['target_score'],
                    $config['formula'],
                    $config['max_handicap'],
                    $isFirstMatchday,
                    $previous !== null ? (int) $previous['handicap'] : null,
                );
            }
        }

        return (new HandicapChainRecalculation($this->handicapModule))
            ->recalculateHandicapChain(max(1, $fromMatchdayCounter), $chain);
    }

    public function applyChain(HandicapChainPreview $preview): void
    {
        $this->freezeService->applyHandicapChainPreview($preview);
    }

    public function stats(int $matchdayId, int $playerId): array
    {
        $context = $this->matchdayContext($matchdayId);
        if ($context === null) {
            return ['average' => null, 'games' => 0, 'hdc' => null, 'frozen' => false, 'first_matchday' => false];
        }

        $config = $this->configuration($context['season_id']);
        $counter = self::averageCounter($context, false);
        $input = $this->averageInput($playerId, $context, false, $config['previous_season_matchdays']);
        $average = $this->averages->calculateExactAverage($counter, $input, $config['previous_season_matchdays']);

        $frozen = $this->frozen->findForMatchdayAndPlayer($matchdayId, $playerId);
        if ($frozen !== null) {
            $hdc = (int) $frozen['handicap'];
        } else {
            $result = $this->handicapModule->calculateHandicap(
                $average,
                $config['target_score'],
                $config['formula'],
                $config['max_handicap'],
            );
            $hdc = $result->hasHandicap() ? $result->handicap() : null;
        }

        return [
            'average' => $average,
            'games'       => self::numberOfGames($input, $counter, $config['previous_season_matchdays']),
            'hdc'          => $hdc,
            'frozen'  => $frozen !== null,
            'first_matchday' => $average === null && $frozen === null,
        ];
    }

    public function averageBefore(int $matchdayId, int $playerId): ?float
    {
        return $this->stats($matchdayId, $playerId)['average'];
    }

    public function gamesPlayedBefore(int $matchdayId, int $playerId): int
    {
        $context = $this->matchdayContext($matchdayId);
        if ($context === null) {
            return 0;
        }

        $config = $this->configuration($context['season_id']);

        return self::numberOfGames(
            $this->averageInput($playerId, $context, false, $config['previous_season_matchdays']),
            self::averageCounter($context, false),
            $config['previous_season_matchdays'],
        );
    }

    public function formula(int $seasonId): ?array
    {
        $config = $this->configuration($seasonId);
        if ($config['formula'] === null) {
            return null;
        }

        return [
            'factor'   => $config['formula']->factor(),
            'target_score' => $config['target_score'],
            'max'      => $config['max_handicap'],
        ];
    }

    public function pointsRules(int $seasonId): PointsRules
    {
        return SeasonConfiguration::pointsRulesFromRow($this->findOne(
            'SELECT win_points, draw_points, loss_points, bonus_threshold, bonus_step, bonus_maximum
             FROM season_config WHERE season_id = :id',
            ['id' => $seasonId]
        ));
    }

    /**
     * Reads a stored handicap formula (R12.2, R12.3): the identifier
     * "standard" (optionally with factor) or the notation
     * "HDC = (Zielwert - Durchschnitt) * 0,8". Null if there is no readable
     * formula (R12.13).
     */
    public static function readFormula(?string $saved): ?HandicapFormula
    {
        if ($saved === null || trim($saved) === '') {
            return null;
        }

        $standard = HandicapFormula::fromIdentifier($saved);
        if ($standard !== null) {
            return $standard;
        }

        $pattern = '/^\s*(?:HDC\s*=\s*)?\(\s*Zielwert\s*-\s*Durchschnitt\s*\)\s*[*×x]\s*(\d+(?:[.,]\d+)?)\s*$/iu';
        if (preg_match($pattern, $saved, $matches) === 1) {
            return HandicapFormula::withFactor((float) str_replace(',', '.', $matches[1]));
        }

        return null;
    }

    /**
     * Average for the handicap of a matchday: state before the matchday;
     * without it (first matchday, R12.9) the state at the end of the matchday.
     *
     * @param array{matchday_id: int, season_id: int, matchday_number: int} $context
     *
     * @return array{0: float|null, 1: bool} Average and first matchday flag
     */
    private function averageForHandicap(int $playerId, array $context, int $previousSeasonMatchdays): array
    {
        $before = $this->average($playerId, $context, false, $previousSeasonMatchdays);
        if ($before !== null) {
            return [$before, false];
        }

        return [$this->average($playerId, $context, true, $previousSeasonMatchdays), true];
    }

    /**
     * @param array{matchday_id: int, season_id: int, matchday_number: int} $context
     */
    private function average(int $playerId, array $context, bool $includingMatchday, int $previousSeasonMatchdays): ?float
    {
        return $this->averages->calculateExactAverage(
            self::averageCounter($context, $includingMatchday),
            $this->averageInput($playerId, $context, $includingMatchday, $previousSeasonMatchdays),
            $previousSeasonMatchdays,
        );
    }

    /**
     * Matchday counter of the average state: after the previous matchday for
     * the handicap of a matchday (0 before the first one), after the matchday
     * itself in the first matchday special case (R12.8, R12.9).
     *
     * @param array{matchday_id: int, season_id: int, matchday_number: int} $context
     */
    private static function averageCounter(array $context, bool $includingMatchday): int
    {
        return $includingMatchday ? $context['matchday_number'] : max(0, $context['matchday_number'] - 1);
    }

    /**
     * Number of games the average is based on (same inclusion rules as the
     * {@see AverageModule}, R11.3–R11.6).
     */
    private static function numberOfGames(AverageInput $input, int $matchdayCounter, int $previousSeasonMatchdays): int
    {
        $quantity = count($input->realGamesCurrentSeason());
        if (AverageModule::includesPreviousSeason($matchdayCounter, $previousSeasonMatchdays)) {
            $previousSeason = count($input->realGamesPreviousSeason());
            if ($previousSeason > 0) {
                $quantity += $previousSeason;
            } elseif ($input->previousSeasonRecord !== null && $input->previousSeasonRecord->isIncludable()) {
                $quantity += $input->previousSeasonRecord->numberOfGames;
            }
        }

        return $quantity;
    }

    /**
     * @param array{matchday_id: int, season_id: int, matchday_number: int} $context
     */
    private function averageInput(int $playerId, array $context, bool $includingMatchday, int $previousSeasonMatchdays): AverageInput
    {
        $games = [];

        // Current season: complete fixtures of earlier matchdays, on the
        // first matchday also the results of this matchday.
        $rows = $this->findAll(
            "SELECT e.net, (e.is_blind = 1 OR a.is_blind = 1) AS blind, ts.is_ghost_team
             FROM game_result e
             JOIN lineup a ON a.id = e.lineup_id
             JOIN team_season ts ON ts.id = a.team_season_id
             JOIN fixture fx ON fx.id = a.fixture_id
             JOIN matchday md ON md.id = fx.matchday_id
             WHERE a.player_id = :player_id AND md.season_id = :season_id
               AND ((md.matchday_number < :number AND fx.completion_status = 'complete')
                    OR (:including = 1 AND md.id = :matchday_id))",
            [
                'player_id'  => $playerId,
                'season_id'   => $context['season_id'],
                'number'     => $context['matchday_number'],
                'including'   => $includingMatchday ? 1 : 0,
                'matchday_id' => $context['matchday_id'],
            ]
        );
        foreach ($rows as $z) {
            $games[] = new Game((int) $z['net'], (int) $z['blind'] === 1, (int) $z['is_ghost_team'] === 1);
        }

        $record = null;
        if (AverageModule::includesPreviousSeason(self::averageCounter($context, $includingMatchday), $previousSeasonMatchdays)) {
            // The season's own previous season record takes precedence over the
            // games of the preceding season (R29.2, R29.11); only without one
            // do those games count.
            $row = $this->findOne(
                'SELECT game_count, net_pins_total FROM previous_season_record WHERE season_id = :season_id AND player_id = :id',
                ['season_id' => $context['season_id'], 'id' => $playerId]
            );
            if ($row !== null) {
                $record = new PreviousSeasonRecord((int) $row['game_count'], (int) $row['net_pins_total']);
            } else {
                foreach ($this->previousSeasonGames($playerId, $context['season_id']) as $z) {
                    $games[] = new Game((int) $z['net'], (int) $z['blind'] === 1, (int) $z['is_ghost_team'] === 1, true);
                }
            }
        }

        return new AverageInput($games, $record);
    }

    /**
     * The player's games played in the immediately preceding season (R11.3).
     *
     * @return list<array<string, mixed>>
     */
    private function previousSeasonGames(int $playerId, int $seasonId): array
    {
        return $this->findAll(
            "SELECT e.net, (e.is_blind = 1 OR a.is_blind = 1) AS blind, ts.is_ghost_team
             FROM game_result e
             JOIN lineup a ON a.id = e.lineup_id
             JOIN team_season ts ON ts.id = a.team_season_id
             JOIN fixture fx ON fx.id = a.fixture_id AND fx.completion_status = 'complete'
             JOIN matchday md ON md.id = fx.matchday_id
             WHERE a.player_id = :player_id
               AND md.season_id = (
                   SELECT v.id FROM season v, season s
                   WHERE s.id = :season_id AND v.start_date < s.start_date
                   ORDER BY v.start_date DESC LIMIT 1
               )",
            ['player_id' => $playerId, 'season_id' => $seasonId]
        );
    }

    /**
     * Players of a matchday with results, without the ghost team – including
     * the players substituted for blind positions, whose handicap is added to
     * the blind results (R27.7, R27.12).
     *
     * @return list<int>
     */
    private function playersOfMatchday(int $matchdayId): array
    {
        $rows = $this->findAll(
            'SELECT DISTINCT a.player_id
             FROM lineup a
             JOIN fixture fx ON fx.id = a.fixture_id
             JOIN team_season ts ON ts.id = a.team_season_id AND ts.is_ghost_team = 0
             JOIN game_result e ON e.lineup_id = a.id
             WHERE fx.matchday_id = :matchday_id AND a.player_id IS NOT NULL
             ORDER BY a.player_id',
            ['matchday_id' => $matchdayId]
        );

        return array_map(static fn (array $z): int => (int) $z['player_id'], $rows);
    }

    /**
     * @return array{matchday_id: int, season_id: int, matchday_number: int}|null
     */
    private function matchdayContext(int $matchdayId): ?array
    {
        $row = $this->findOne(
            'SELECT id, season_id, matchday_number FROM matchday WHERE id = :id',
            ['id' => $matchdayId]
        );

        return $row === null ? null : [
            'matchday_id'      => (int) $row['id'],
            'season_id'        => (int) $row['season_id'],
            'matchday_number' => (int) $row['matchday_number'],
        ];
    }

    /**
     * Target score, maximum handicap and formula of the season; without a
     * configuration the schema defaults without formula (no handicap, R12.13).
     *
     * @return array{target_score: int, max_handicap: int, formula: HandicapFormula|null, previous_season_matchdays: int}
     */
    private function configuration(int $seasonId): array
    {
        $row = $this->findOne(
            'SELECT target_score, max_handicap, handicap_formula, previous_season_matchdays FROM season_config WHERE season_id = :id',
            ['id' => $seasonId]
        );

        return [
            'target_score'     => $row !== null ? (int) $row['target_score'] : SeasonConfiguration::DEFAULT_TARGET_SCORE,
            'max_handicap' => $row !== null ? (int) $row['max_handicap'] : SeasonConfiguration::DEFAULT_MAX_HANDICAP,
            'previous_season_matchdays' => $row !== null
                ? (int) $row['previous_season_matchdays']
                : SeasonConfiguration::DEFAULT_PREVIOUS_SEASON_MATCHDAYS,
            'formula'       => self::readFormula($row !== null && $row['handicap_formula'] !== null ? (string) $row['handicap_formula'] : null),
        ];
    }
}
