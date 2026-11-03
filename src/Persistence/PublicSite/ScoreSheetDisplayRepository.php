<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\PublicSite;

use Bowlingliga\Application\PublicSite\ScoreSheetRow;
use Bowlingliga\Application\PublicSite\ScoreSheetTeamView;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Read repository for the public, read-only score sheet view of a team within
 * a fixture (R15.1, R2.1, R22.1).
 *
 * Returns per team the rows of the score sheet with player name, the net
 * values of the games played in the row and the gross values formed from
 * them with the frozen handicap of the row's player (net + HDC, R17.2). For
 * the ghost team or without a frozen handicap the handicap is 0 (R20.2). A
 * row without a player or of a blind player counts as blind.
 *
 * Read-only via prepared statements (R4.1), unchanged return (R4.5).
 */
final class ScoreSheetDisplayRepository extends AbstractRepository
{
    /**
     * Builds the score sheet view of a team within a fixture.
     *
     * @param int         $fixtureId    Fixture
     * @param int         $teamSeasonId Team of the fixture
     * @param int         $matchdayId   Matchday (for the frozen handicap)
     * @param string|null $teamName     Display name of the team
     */
    public function forTeam(int $fixtureId, int $teamSeasonId, int $matchdayId, ?string $teamName): ScoreSheetTeamView
    {
        $lineups = $this->findAll(
            'SELECT a.id AS lineup_id, a.player_id, a.is_blind, s.name AS player_name
             FROM lineup a
             LEFT JOIN player s ON s.id = a.player_id
             WHERE a.fixture_id = :fx AND a.team_season_id = :ts
             ORDER BY a.sheet_row',
            ['fx' => $fixtureId, 'ts' => $teamSeasonId]
        );

        $rows = [];
        foreach ($lineups as $row) {
            $playerId = $row['player_id'] === null ? null : (int) $row['player_id'];
            $handicap = $playerId === null ? 0 : $this->rowHandicap($matchdayId, $playerId);

            [$net, $blindGames] = $this->readNet((int) $row['lineup_id']);
            // Blind player (or legacy data: all three games blind); otherwise
            // individual blind games of a player (R27.5).
            $rowBlind = $playerId === null
                || (int) $row['is_blind'] === 1
                || (count($blindGames) === 3 && ! in_array(false, $blindGames, true));

            $rows[] = new ScoreSheetRow(
                $row['player_name'] === null ? null : (string) $row['player_name'],
                $rowBlind,
                $net,
                array_map(static fn (int $value): int => $value + $handicap, $net),
                $rowBlind ? [] : $blindGames,
            );
        }

        return new ScoreSheetTeamView($teamName, $rows);
    }

    /**
     * Reads the net values of a lineup row by game number including the blind
     * flag; games not played in the row are missing.
     *
     * @return array{0: array<int, int>, 1: array<int, bool>}
     */
    private function readNet(int $lineupId): array
    {
        $rows = $this->findAll(
            'SELECT game_number, net, is_blind FROM game_result
             WHERE lineup_id = :lineup ORDER BY game_number',
            ['lineup' => $lineupId]
        );

        $net   = [];
        $blind = [];
        foreach ($rows as $row) {
            $num = (int) $row['game_number'];
            if ($num >= 1 && $num <= 3) {
                $net[$num]   = (int) $row['net'];
                $blind[$num] = (int) $row['is_blind'] === 1;
            }
        }

        return [$net, $blind];
    }

    private function rowHandicap(int $matchdayId, int $playerId): int
    {
        $row = $this->findOne(
            'SELECT handicap FROM frozen_handicap
             WHERE matchday_id = :sp AND player_id = :player',
            ['sp' => $matchdayId, 'player' => $playerId]
        );

        return $row === null ? 0 : (int) $row['handicap'];
    }
}
