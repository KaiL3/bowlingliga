<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\ResultEntry;

use Bowlingliga\Application\ResultEntry\FixtureLogEntry;
use Bowlingliga\Application\ResultEntry\FixtureLogReader;
use Bowlingliga\Application\ResultEntry\ResultLog;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Persistence\Database;

/**
 * History of a fixture in table `fixture_log`: entries of the
 * teams (side home/away) and of the admin (side null, actor = username of the
 * logged-in account). One row per changed value.
 */
final class FixtureLogRepository extends AbstractRepository implements ResultLog, FixtureLogReader
{
    /**
     * @param \Closure(): ?string|null $adminName username of the logged-in
     *        admin account, stored with the admin's entries
     */
    public function __construct(Database $database, private readonly ?\Closure $adminName = null)
    {
        parent::__construct($database);
    }

    public function changed(int $fixtureId, int $playerId, int $gameNumber, ?string $before, ?string $after): void
    {
        $this->append($fixtureId, null, 'net', $playerId, $gameNumber, $before, $after);
    }

    public function noted(int $fixtureId, string $action, string $detail = ''): void
    {
        $this->append($fixtureId, null, $action, detail: $detail);
    }

    /**
     * Appends an entry; side null = admin (with the account name).
     */
    public function append(
        int $fixtureId,
        ?string $side,
        string $action,
        ?int $playerId = null,
        ?int $gameNumber = null,
        ?string $before = null,
        ?string $after = null,
        string $detail = '',
    ): void {
        $this->transactional('Verlauf der Paarung schreiben', function () use ($fixtureId, $side, $action, $playerId, $gameNumber, $before, $after, $detail): void {
            $this->perform(
                'INSERT INTO fixture_log (fixture_id, side, actor, action, player_id, game_number, value_before, value_after, detail)
                 VALUES (:fixture, :side, :actor, :action, :player, :game, :before, :after, :detail)',
                [
                    'fixture' => $fixtureId,
                    'side'    => $side,
                    'actor'   => $side === null && $this->adminName !== null ? ($this->adminName)() : null,
                    'action'  => $action,
                    'player'  => $playerId,
                    'game'    => $gameNumber,
                    'before'  => $before,
                    'after'   => $after,
                    // The reason when sending back is limited to 500 characters already.
                    'detail'  => mb_substr($detail, 0, 500),
                ]
            );
        });
    }

    public function entries(int $fixtureId): array
    {
        $rows = $this->findAll(
            'SELECT l.created_at, l.side, l.actor, l.action, l.player_id, p.name AS player_name,
                    l.game_number, l.value_before, l.value_after, l.detail
             FROM fixture_log l
             LEFT JOIN player p ON p.id = l.player_id
             WHERE l.fixture_id = :fixture
             ORDER BY l.id DESC',
            ['fixture' => $fixtureId]
        );

        return array_map(static fn (array $row): FixtureLogEntry => new FixtureLogEntry(
            (string) $row['created_at'],
            $row['side'] !== null ? (string) $row['side'] : null,
            $row['actor'] !== null ? (string) $row['actor'] : null,
            (string) $row['action'],
            $row['player_id'] !== null ? (int) $row['player_id'] : null,
            $row['player_name'] !== null ? (string) $row['player_name'] : null,
            $row['game_number'] !== null ? (int) $row['game_number'] : null,
            $row['value_before'] !== null ? (string) $row['value_before'] : null,
            $row['value_after'] !== null ? (string) $row['value_after'] : null,
            (string) $row['detail'],
        ), $rows);
    }
}
