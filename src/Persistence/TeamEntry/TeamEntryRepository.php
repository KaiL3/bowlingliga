<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\TeamEntry;

use Bowlingliga\Application\TeamEntry\TeamEntrySide;
use Bowlingliga\Application\TeamEntry\TeamEntryStore;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\ResultEntry\FixtureLogRepository;

/**
 * Persistence of the result entry by the teams (tables `team_entry`,
 * `team_entry_game`, column `season.team_entry`; R37) and its
 * entries in the history of the fixture (`fixture_log`). Write
 * operations transactional, prepared statements only.
 */
final class TeamEntryRepository extends AbstractRepository implements TeamEntryStore
{
    private const SIDES = ['home', 'away'];

    private readonly FixtureLogRepository $history;

    /**
     * @param \Closure(): ?string|null $adminName username of the logged-in admin
     *        account for the admin's steps (takeover, new codes)
     */
    public function __construct(Database $database, ?\Closure $adminName = null)
    {
        parent::__construct($database);
        $this->history = new FixtureLogRepository($database, $adminName);
    }

    public function isEnabled(int $seasonId): bool
    {
        $row = $this->findOne('SELECT team_entry FROM season WHERE id = :id', ['id' => $seasonId]);

        return $row !== null && (int) $row['team_entry'] === 1;
    }

    public function setEnabled(int $seasonId, bool $enabled): void
    {
        $this->transactional('switch_team_entry', function () use ($seasonId, $enabled): void {
            $this->perform('UPDATE season SET team_entry = :on WHERE id = :id', ['on' => $enabled ? 1 : 0, 'id' => $seasonId]);
        });
    }

    public function fixture(int $fixtureId): ?array
    {
        $row = $this->findOne(
            'SELECT fx.id, fx.matchday_id, fx.completion_status, fx.home_team_season_id, fx.away_team_season_id,
                    md.season_id, md.matchday_number, md.date, md.is_cancelled, lp.lane_a, lp.lane_b,
                    COALESCE(sc.ghost_score, ' . \Bowlingliga\Application\SeasonConfiguration::DEFAULT_GHOST_SCORE . ') AS ghost_score
             FROM fixture fx
             JOIN matchday md ON md.id = fx.matchday_id
             LEFT JOIN lane_pair lp ON lp.id = fx.lane_pair_id
             LEFT JOIN season_config sc ON sc.season_id = md.season_id
             WHERE fx.id = :id',
            ['id' => $fixtureId]
        );
        if ($row === null) {
            return null;
        }

        return [
            'id'              => (int) $row['id'],
            'matchday_id'     => (int) $row['matchday_id'],
            'season_id'       => (int) $row['season_id'],
            'matchday_number' => (int) $row['matchday_number'],
            'date'            => (string) $row['date'],
            'is_cancelled'    => (int) $row['is_cancelled'] === 1,
            'lanes'           => $row['lane_a'] !== null ? $row['lane_a'] . '/' . $row['lane_b'] : null,
            'status'          => (string) $row['completion_status'],
            'ghost_score'     => (int) $row['ghost_score'],
            'home'            => $this->team($row['home_team_season_id']),
            'away'            => $this->team($row['away_team_season_id']),
        ];
    }

    public function sides(int $fixtureId): array
    {
        $sides = [];
        foreach (self::SIDES as $side) {
            $sides[$side] = new TeamEntrySide($side);
        }

        $rows = $this->findAll(
            'SELECT side, code_version, submitted_at, confirmed_at, rejection_note, closed_at
             FROM team_entry WHERE fixture_id = :id',
            ['id' => $fixtureId]
        );
        foreach ($rows as $row) {
            $sides[$row['side']] = new TeamEntrySide(
                (string) $row['side'],
                (int) $row['code_version'],
                $row['submitted_at'] !== null,
                $row['confirmed_at'] !== null,
                $row['rejection_note'] !== null ? (string) $row['rejection_note'] : null,
                $row['closed_at'] !== null,
            );
        }

        return $sides;
    }

    public function games(int $fixtureId): array
    {
        $rows = $this->findAll(
            'SELECT team_season_id, player_id, game_number, is_blind, net
             FROM team_entry_game WHERE fixture_id = :id ORDER BY team_season_id, player_id, game_number',
            ['id' => $fixtureId]
        );

        return array_map(static fn (array $r): array => [
            'team_season_id' => (int) $r['team_season_id'],
            'player_id'      => (int) $r['player_id'],
            'game_number'    => (int) $r['game_number'],
            'is_blind'       => (int) $r['is_blind'] === 1,
            'net'            => $r['net'] !== null ? (int) $r['net'] : null,
        ], $rows);
    }

    public function lock(int $fixtureId): void
    {
        $this->perform('SELECT id FROM fixture WHERE id = :id FOR UPDATE', ['id' => $fixtureId]);
    }

    public function setParticipation(int $fixtureId, int $teamSeasonId, int $playerId, int $gameNumber, ?bool $isBlind): void
    {
        $this->transactional('save_team_entry_lineup', function () use ($fixtureId, $teamSeasonId, $playerId, $gameNumber, $isBlind): void {
            if ($isBlind === null) {
                $this->perform(
                    'DELETE FROM team_entry_game WHERE fixture_id = :f AND player_id = :p AND game_number = :g',
                    ['f' => $fixtureId, 'p' => $playerId, 'g' => $gameNumber]
                );

                return;
            }

            $this->perform(
                'INSERT INTO team_entry_game (fixture_id, team_season_id, player_id, game_number, is_blind)
                 VALUES (:f, :t, :p, :g, :b)
                 ON DUPLICATE KEY UPDATE is_blind = VALUES(is_blind)',
                ['f' => $fixtureId, 't' => $teamSeasonId, 'p' => $playerId, 'g' => $gameNumber, 'b' => $isBlind ? 1 : 0]
            );
        });
    }

    public function setNet(int $fixtureId, int $playerId, int $gameNumber, ?int $net): void
    {
        $this->transactional('save_team_entry_result', function () use ($fixtureId, $playerId, $gameNumber, $net): void {
            $this->perform(
                'UPDATE team_entry_game SET net = :n WHERE fixture_id = :f AND player_id = :p AND game_number = :g',
                ['n' => $net, 'f' => $fixtureId, 'p' => $playerId, 'g' => $gameNumber]
            );
        });
    }

    public function setSubmitted(int $fixtureId, string $side, bool $submitted): void
    {
        $this->updateSide($fixtureId, $side, 'submitted_at = ' . ($submitted ? 'NOW()' : 'NULL'));
    }

    public function setConfirmed(int $fixtureId, string $side): void
    {
        $this->updateSide($fixtureId, $side, 'confirmed_at = NOW()');
    }

    public function setRejection(int $fixtureId, string $side, ?string $note): void
    {
        $this->updateSide($fixtureId, $side, 'rejection_note = :note', ['note' => $note]);
    }

    public function close(int $fixtureId): void
    {
        foreach (self::SIDES as $side) {
            $this->updateSide($fixtureId, $side, 'closed_at = COALESCE(closed_at, NOW())');
        }
    }

    public function renewCodes(int $fixtureId): void
    {
        foreach (self::SIDES as $side) {
            $this->updateSide($fixtureId, $side, 'code_version = code_version + 1');
        }
    }

    public function log(int $fixtureId, ?string $side, string $action, string $detail = ''): void
    {
        $this->history->append($fixtureId, $side, $action, detail: $detail);
    }

    public function logChange(int $fixtureId, string $side, string $action, int $playerId, int $gameNumber, ?string $before, ?string $after): void
    {
        $this->history->append($fixtureId, $side, $action, $playerId, $gameNumber, $before, $after);
    }

    public function activeFixtures(int $seasonId): array
    {
        $rows = $this->findAll(
            "SELECT fx.id, md.matchday_number, md.date, h.team_name AS home, a.team_name AS away
             FROM fixture fx
             JOIN matchday md ON md.id = fx.matchday_id
             JOIN team_season h ON h.id = fx.home_team_season_id
             JOIN team_season a ON a.id = fx.away_team_season_id
             WHERE md.season_id = :season AND md.is_cancelled = 0 AND fx.completion_status <> 'complete'
               AND (EXISTS (SELECT 1 FROM team_entry_game g WHERE g.fixture_id = fx.id)
                    OR EXISTS (SELECT 1 FROM team_entry e WHERE e.fixture_id = fx.id
                               AND (e.submitted_at IS NOT NULL OR e.confirmed_at IS NOT NULL OR e.rejection_note IS NOT NULL)))
               AND NOT EXISTS (SELECT 1 FROM team_entry e WHERE e.fixture_id = fx.id AND e.closed_at IS NOT NULL)
             ORDER BY md.matchday_number, fx.id",
            ['season' => $seasonId]
        );

        return array_map(static fn (array $r): array => [
            'fixture_id'      => (int) $r['id'],
            'matchday_number' => (int) $r['matchday_number'],
            'date'            => (string) $r['date'],
            'home'            => (string) $r['home'],
            'away'            => (string) $r['away'],
        ], $rows);
    }

    /** @param array<string, mixed> $parameters */
    private function updateSide(int $fixtureId, string $side, string $assignment, array $parameters = []): void
    {
        $this->transactional('save_team_entry_state', function () use ($fixtureId, $side, $assignment, $parameters): void {
            $this->perform(
                'INSERT INTO team_entry (fixture_id, side) VALUES (:f, :s) ON DUPLICATE KEY UPDATE fixture_id = fixture_id',
                ['f' => $fixtureId, 's' => $side]
            );
            $this->perform(
                'UPDATE team_entry SET ' . $assignment . ' WHERE fixture_id = :f AND side = :s',
                ['f' => $fixtureId, 's' => $side] + $parameters
            );
        });
    }

    /** @return array{team_season_id: int, team_name: string, is_ghost_team: bool, players: array<int, string>}|null */
    private function team(mixed $teamSeasonId): ?array
    {
        if ($teamSeasonId === null) {
            return null;
        }

        $row = $this->findOne(
            'SELECT id, team_name, is_ghost_team FROM team_season WHERE id = :id',
            ['id' => (int) $teamSeasonId]
        );
        if ($row === null) {
            return null;
        }

        $players = [];
        foreach ($this->findAll(
            'SELECT p.id, p.name FROM roster r JOIN player p ON p.id = r.player_id
             WHERE r.team_season_id = :id ORDER BY p.name',
            ['id' => (int) $teamSeasonId]
        ) as $player) {
            $players[(int) $player['id']] = (string) $player['name'];
        }

        return [
            'team_season_id' => (int) $row['id'],
            'team_name'      => (string) $row['team_name'],
            'is_ghost_team'  => (int) $row['is_ghost_team'] === 1,
            'players'        => $players,
        ];
    }
}
