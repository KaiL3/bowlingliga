<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\Aggregation;

use Bowlingliga\Application\Aggregation\GameRawData;
use Bowlingliga\Application\Aggregation\AverageListRawDataReader;
use Bowlingliga\Application\Aggregation\PlayerRawData;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Persistence adapter of the {@see AverageListRawDataReader} port (R2.1, R2.8,
 * R35.2).
 *
 * Returns per player of a team (team season) the net games entered up to and
 * including the matchday counter in question, in game order, together with
 * the blind flag (R27.13) and the ghost team flag (R20.4), as well as the
 * frozen handicap assigned to the matchday in question (R2.8) or null for
 * "without handicap" (R2.15). Excluding games marked blind and ghost team
 * games deliberately only happens in the pure
 * {@see \Bowlingliga\Application\Aggregation\AverageListAggregator}.
 *
 * Only real players (lineup with `player_id IS NOT NULL`) are returned; blind
 * positions belong to no player and do not appear in the average list. All
 * access is read-only via prepared statements (R4.1), unchanged return
 * (R4.5).
 */
final class AverageListRawDataRepository extends AbstractRepository implements AverageListRawDataReader
{
    public function findPlayerRawData(int $teamSeasonId, int $toMatchdayCounter): array
    {
        // Determine the team's season, to map the matchday counter to concrete
        // matchday ids.
        $teamSeason = $this->findOne(
            'SELECT season_id, is_ghost_team FROM team_season WHERE id = :id',
            ['id' => $teamSeasonId]
        );
        if ($teamSeason === null) {
            return [];
        }

        $seasonId       = (int) $teamSeason['season_id'];
        $isGhostTeam = (int) $teamSeason['is_ghost_team'] === 1;

        // The matchday belonging to the counter (for the frozen handicap).
        $matchday = $this->findOne(
            'SELECT id FROM matchday WHERE season_id = :season AND matchday_number = :z',
            ['season' => $seasonId, 'z' => $toMatchdayCounter]
        );
        $matchdayId = $matchday === null ? null : (int) $matchday['id'];

        // All real players lined up for this team up to the state in question.
        $player = $this->findAll(
            'SELECT DISTINCT s.id AS player_id, s.name, s.gender
             FROM lineup a
             JOIN matchday md ON md.id = (
                 SELECT fx.matchday_id FROM fixture fx WHERE fx.id = a.fixture_id
             )
             JOIN player s ON s.id = a.player_id
             WHERE a.team_season_id = :ts
               AND a.player_id IS NOT NULL
               AND md.season_id = :season
               AND md.matchday_number <= :z
             ORDER BY s.name',
            ['ts' => $teamSeasonId, 'season' => $seasonId, 'z' => $toMatchdayCounter]
        );

        $result = [];
        foreach ($player as $row) {
            $playerId = (int) $row['player_id'];

            $result[] = new PlayerRawData(
                (string) $playerId,
                (string) $row['name'],
                $this->gender((string) $row['gender']),
                $this->readGames($teamSeasonId, $playerId, $seasonId, $toMatchdayCounter, $isGhostTeam),
                $matchdayId === null ? null : $this->frozenHandicap($matchdayId, $playerId),
            );
        }

        return $result;
    }

    /**
     * Reads a player's games for a team up to the matchday state, in game
     * order (by matchday counter, position, game number).
     *
     * @return list<GameRawData>
     */
    private function readGames(
        int $teamSeasonId,
        int $playerId,
        int $seasonId,
        int $toMatchdayCounter,
        bool $isGhostTeam
    ): array {
        $rows = $this->findAll(
            'SELECT e.net, (e.is_blind = 1 OR a.is_blind = 1) AS is_blind
             FROM game_result e
             JOIN lineup a ON a.id = e.lineup_id
             JOIN fixture fx ON fx.id = a.fixture_id
             JOIN matchday md ON md.id = fx.matchday_id
             WHERE a.team_season_id = :ts
               AND a.player_id = :player
               AND md.season_id = :season
               AND md.matchday_number <= :z
             ORDER BY md.matchday_number, a.sheet_row, e.game_number',
            ['ts' => $teamSeasonId, 'player' => $playerId, 'season' => $seasonId, 'z' => $toMatchdayCounter]
        );

        $games = [];
        foreach ($rows as $row) {
            $games[] = new GameRawData(
                (int) $row['net'],
                (int) $row['is_blind'] === 1,
                $isGhostTeam,
            );
        }

        return $games;
    }

    private function frozenHandicap(int $matchdayId, int $playerId): ?int
    {
        $row = $this->findOne(
            'SELECT handicap FROM frozen_handicap
             WHERE matchday_id = :sp AND player_id = :player',
            ['sp' => $matchdayId, 'player' => $playerId]
        );

        return $row === null ? null : (int) $row['handicap'];
    }

    private function gender(string $enumValue): Gender
    {
        // The DB ENUM and the honours enum use the same values ('male'/'female').
        return Gender::from($enumValue);
    }
}
