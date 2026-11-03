<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\Aggregation;

use Bowlingliga\Application\Aggregation\HonoursRawDataReader;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\Honours\GameResultInput;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Persistence adapter of the {@see HonoursRawDataReader} port (R13, R35.2).
 *
 * Returns all raw games entered for a season as {@see GameResultInput} –
 * real, blind and ghost team mixed – and whether the season's end date has
 * passed (R13.8/R13.10). The exclusion and series logic is handled entirely
 * by the pure {@see \Bowlingliga\Domain\Honours\HonoursModule}; this adapter
 * only supplies the raw data and the visibility flag.
 *
 * The evening for forming series is the matchday (whose id serves as
 * `eveningId`, R13.2). The handicap per game is the player's frozen HDC on
 * the matchday (R13.3), or 0 for the ghost team (R20.2) or if there is no
 * frozen handicap. Only real players (`lineup.player_id IS NOT NULL`)
 * supply results; blind positions belong to no player.
 *
 * All access is read-only via prepared statements (R4.1), unchanged return
 * (R4.5).
 */
final class HonoursRawDataRepository extends AbstractRepository implements HonoursRawDataReader
{
    public function findGameResults(int $seasonId): array
    {
        $rows = $this->findAll(
            'SELECT e.net,
                    (e.is_blind = 1 OR a.is_blind = 1) AS is_blind,
                    md.id AS matchday_id,
                    s.id AS player_id,
                    s.name AS player_name,
                    s.gender AS gender,
                    ts.is_ghost_team AS is_ghost_team,
                    h.handicap AS handicap
             FROM game_result e
             JOIN lineup a ON a.id = e.lineup_id
             JOIN fixture fx ON fx.id = a.fixture_id
             JOIN matchday md ON md.id = fx.matchday_id
             JOIN team_season ts ON ts.id = a.team_season_id
             JOIN player s ON s.id = a.player_id
             LEFT JOIN frozen_handicap h
                    ON h.matchday_id = md.id AND h.player_id = a.player_id
             WHERE md.season_id = :season
               AND a.player_id IS NOT NULL
             ORDER BY md.matchday_number, s.id, a.sheet_row, e.game_number',
            ['season' => $seasonId]
        );

        $results = [];
        foreach ($rows as $row) {
            $isGhostTeam = (int) $row['is_ghost_team'] === 1;
            $handicap       = $isGhostTeam || $row['handicap'] === null
                ? 0
                : (int) $row['handicap'];

            $results[] = new GameResultInput(
                (int) $row['player_id'],
                $this->gender((string) $row['gender']),
                (int) $row['matchday_id'],
                (int) $row['net'],
                $handicap,
                (int) $row['is_blind'] === 1,
                $isGhostTeam,
                (string) $row['player_name'],
            );
        }

        return $results;
    }

    public function isAfterEndDate(int $seasonId): bool
    {
        $row = $this->findOne(
            'SELECT end_date FROM season WHERE id = :id',
            ['id' => $seasonId]
        );
        if ($row === null) {
            return false;
        }

        $endDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['end_date']);
        if ($endDate === false) {
            return false;
        }

        // After the end date = from the day after the end date (R13.8/R13.10).
        $today = new \DateTimeImmutable('today');

        return $today > $endDate;
    }

    private function gender(string $enumValue): Gender
    {
        return str_starts_with(mb_strtolower($enumValue), 'm')
            ? Gender::MALE
            : Gender::FEMALE;
    }
}
