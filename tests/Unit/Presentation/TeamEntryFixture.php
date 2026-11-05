<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\ResultEntry\BlindFilling;
use Bowlingliga\Application\ResultEntry\BlindPlayerOrder;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Application\TeamEntry\TeamEntryWriteAccess;
use Bowlingliga\Application\TeamEntry\TeamResultEntry;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Presentation\PlayerStatsReader;
use Bowlingliga\Presentation\TeamEntryController;
use Bowlingliga\Presentation\TeamEntryView;
use Bowlingliga\Security\TeamEntryCodes;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\Unit\Application\ImmediateTransaction;
use Bowlingliga\Tests\Unit\Application\InMemoryRosterRepository;
use Bowlingliga\Tests\Unit\Application\ResultEntry\FixedMatchdayContext;
use Bowlingliga\Tests\Unit\Application\ResultEntry\FixedPlayedGames;
use Bowlingliga\Tests\Unit\Application\ResultEntry\InMemoryResultStore;
use Bowlingliga\Tests\Unit\Application\ResultEntry\RecordingCacheStore;
use Bowlingliga\Tests\Unit\Application\ResultEntry\RecordingFlagStore;
use Bowlingliga\Tests\Unit\Application\ResultEntry\RecordingHandicapFreezing;
use Bowlingliga\Tests\Unit\Application\ResultEntry\RecordingStatusStore;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use Bowlingliga\Tests\Unit\Application\TeamEntry\InMemoryTeamEntryStore;

/**
 * Team entry page wired with in-memory fakes: fixture 100, "<b>Adler</b>"
 * (home, players 11–15) against Falken (away, players 21–25), on the
 * matchday at 20:00; every player has HDC 20 and no average.
 */
final class TeamEntryFixture
{
    public const FIXTURE = 100;
    public const SEASON = 1;
    public const MATCHDAY = 7;

    public InMemoryTeamEntryStore $store;
    public TeamEntryCodes $codes;
    public FixedPlayedGames $playedGames;
    public InMemoryResultStore $results;
    public TeamResultEntry $entry;
    public TeamEntryController $controller;
    public string $now = '2026-09-02 20:00:00';

    /** @var list<string> Lines the controller wrote to the server log. */
    public array $logged = [];

    public function __construct(string $language = 'de')
    {
        $this->store = new InMemoryTeamEntryStore();
        $this->store->enabled[self::SEASON] = true;
        $this->store->fixtures[self::FIXTURE] = [
            'id' => self::FIXTURE, 'matchday_id' => self::MATCHDAY, 'season_id' => self::SEASON,
            'matchday_number' => 3, 'date' => '2026-09-02', 'lanes' => '1/2', 'status' => 'open', 'ghost_score' => 190,
            'home' => $this->team(10, '<b>Adler</b>', 'Anna'),
            'away' => $this->team(20, 'Falken', 'Bert'),
        ];

        $roster = new InMemoryRosterRepository();
        foreach ([10, 20] as $team) {
            for ($i = 1; $i <= 5; $i++) {
                $roster->create($team, $team + $i);
            }
        }
        $this->playedGames = new FixedPlayedGames();
        $this->results = new InMemoryResultStore();
        $context = new FixedMatchdayContext();
        $context->contexts[self::MATCHDAY] = ['season_id' => self::SEASON, 'matchday_number' => 3];

        $teamAccess = new TeamEntryWriteAccess();
        $adminAccess = new SwitchableWriteAccess(false);
        $recording = fn ($access): ResultRecording => new ResultRecording(
            $this->results,
            new RecordingStatusStore(),
            new RecordingFlagStore(),
            $context,
            new RecordingHandicapFreezing(),
            new CacheManager(new RecordingCacheStore()),
            new Validator(),
            $access,
            $roster,
            $this->playedGames,
        );

        $this->codes = new TeamEntryCodes('test-key');
        $order = new BlindPlayerOrder($roster, $this->playedGames);
        $this->entry = new TeamResultEntry(
            $this->store,
            $this->codes,
            $recording($teamAccess),
            $teamAccess,
            $recording($adminAccess),
            $adminAccess,
            $order,
            new BlindFilling($order, $this->playedGames),
            new ImmediateTransaction(),
            fn (): \DateTimeImmutable => new \DateTimeImmutable($this->now),
        );

        $playedGames = $this->playedGames;
        $stats = new class ($playedGames) implements PlayerStatsReader {
            public function __construct(private readonly FixedPlayedGames $played)
            {
            }

            public function stats(int $matchdayId, int $playerId): array
            {
                $average = $this->played->averages[$playerId] ?? null;

                return ['average' => $average, 'games' => 0, 'hdc' => 20, 'frozen' => false, 'first_matchday' => false];
            }

            public function formula(int $seasonId): ?array
            {
                return null;
            }

            public function pointsRules(int $seasonId): \Bowlingliga\Domain\Points\PointsRules
            {
                return new \Bowlingliga\Domain\Points\PointsRules();
            }
        };

        $translator = new Translator($language);
        $this->controller = new TeamEntryController(
            $this->entry,
            $stats,
            new TeamEntryView($translator),
            t: $translator,
            log: function (string $line): void {
                $this->logged[] = $line;
            },
        );
    }

    public function code(string $side): string
    {
        return $this->codes->create(self::FIXTURE, $side, 1);
    }

    /**
     * Players 1–4 of a side play the game with this net.
     */
    public function enterGame(string $side, int $game, int $net, int $players = 4): void
    {
        $team = $side === 'home' ? 10 : 20;
        $lineup = [];
        $nets = [];
        for ($i = 1; $i <= $players; $i++) {
            $lineup[$team + $i] = 'plays';
            $nets[$team + $i] = (string) $net;
        }
        $this->entry->saveGame($this->code($side), $game, $lineup, $nets);
    }

    public function enterAll(string $side, int $net): void
    {
        foreach ([1, 2, 3] as $game) {
            $this->enterGame($side, $game, $net);
        }
    }

    public function html(string $side, ?int $step = null): string
    {
        return $this->controller->page($this->code($side), $step)['html'];
    }

    /** @return array<string, mixed> */
    private function team(int $id, string $name, string $first): array
    {
        $players = [$id + 1 => $first];
        for ($i = 2; $i <= 5; $i++) {
            $players[$id + $i] = 'Spieler ' . ($id + $i);
        }

        return ['team_season_id' => $id, 'team_name' => $name, 'is_ghost_team' => false, 'players' => $players];
    }
}
