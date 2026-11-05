<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\ResultEntry\BlindPosition;
use Bowlingliga\Application\TeamEntry\TeamEntryException;
use Bowlingliga\Application\TeamEntry\TeamEntrySheet;
use Bowlingliga\Application\TeamEntry\TeamEntrySide;
use Bowlingliga\Application\TeamEntry\TeamResultEntry;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;
use Throwable;

/**
 * Controller of the result entry by the teams (R37): the guided team page via
 * code (game 1–3, overview, confirmation), the actions of the teams and the
 * admin cards.
 *
 * Domain errors become messages on the page; the code from the form is the
 * team's authorisation, so the team routes need no login and no session.
 */
final class TeamEntryController
{
    private const GAMES = TeamResultEntry::GAMES;

    /** Steps of the guided entry: games 1–3, overview, confirmation. */
    public const STEP_OVERVIEW = 4;
    public const STEP_STATUS   = 5;

    /** Shown instead of the database's own message, which the log receives. */
    public const DATABASE_ERROR = 'team_entry.error.database';

    public const ACTIONS = [
        'save'     => 'save',
        'submit'    => 'submit',
        'withdraw' => 'withdraw',
        'confirm'   => 'confirm',
        'reject'  => 'reject',
    ];

    /** Messages (catalog keys) after a successful action, passed on through the redirect as `notice`. */
    public const NOTICES = [
        'sent'      => 'team_entry.notice.sent',
        'final'     => 'team_entry.final',
        'withdrawn' => 'team_entry.notice.withdrawn',
        'confirmed' => 'team_entry.notice.confirmed',
        'rejected'  => 'team_entry.notice.rejected',
    ];

    private readonly Formatter $format;

    /** @var \Closure(string): void Writes a line to the server log. */
    private readonly \Closure $log;

    /**
     * @param (\Closure(string): void)|null $log Server log; default error_log().
     */
    public function __construct(
        private readonly TeamResultEntry $entry,
        private readonly PlayerStatsReader $stats,
        private readonly TeamEntryView $view,
        private readonly ScoreSheetCalculator $calculator = new ScoreSheetCalculator(),
        private readonly QrCode $qrCode = new QrCode(),
        private readonly Translator $t = new Translator(),
        ?\Closure $log = null,
    ) {
        $this->format = new Formatter($t);
        $this->log = $log ?? static function (string $line): void {
            error_log($line);
        };
    }

    /** Text of a notice from the redirect, null for an unknown one. */
    public function notice(?string $name): ?string
    {
        $key = self::NOTICES[$name ?? ''] ?? null;

        return $key !== null ? $this->t->t($key) : null;
    }

    /**
     * Team page; status 404 for an unusable code.
     *
     * @return array{status: int, html: string}
     */
    public function page(string $code, ?int $step = null, ?string $message = null, ?string $error = null): array
    {
        try {
            $sheet = $this->entry->open($code);
        } catch (TeamEntryException $e) {
            return ['status' => 404, 'html' => $this->view->renderUnavailable($this->t->error($e))];
        }

        return ['status' => 200, 'html' => $this->view->renderPage($this->viewData($sheet, $step), $message, $error)];
    }

    /**
     * Only the replaceable part of the page (refreshed by `team-entry.js` on
     * the confirmation step), or null for an unusable code.
     */
    public function sheet(string $code, ?int $step = null): ?string
    {
        try {
            return $this->view->renderSheet($this->viewData($this->entry->open($code), $step), null, null);
        } catch (TeamEntryException) {
            return null;
        }
    }

    /**
     * Runs a team action. `save` saves one game (`game`) and goes on to
     * the step in `next`; going forward checks the game first.
     *
     * @param array<string, mixed> $input
     *
     * @return array{code: string, step: int|null, notice: string|null, error: string|null}
     */
    public function action(string $action, array $input): array
    {
        $code = is_string($input['code'] ?? null) ? $input['code'] : '';
        $method = self::ACTIONS[$action] ?? null;
        $game = filter_var($input['game'] ?? null, FILTER_VALIDATE_INT);
        $game = is_int($game) ? $game : null;

        try {
            [$step, $notice] = match ($method) {
                'save'     => [$this->save($code, (int) $game, $input), null],
                'submit'   => $this->entry->submit($code)
                    ? [self::STEP_OVERVIEW, 'final']
                    : [self::STEP_STATUS, 'sent'],
                'withdraw' => $this->run(fn () => $this->entry->withdraw($code), [self::STEP_OVERVIEW, 'withdrawn']),
                'confirm'  => $this->entry->confirm($code)
                    ? [self::STEP_STATUS, 'final']
                    : [self::STEP_STATUS, 'confirmed'],
                'reject'   => $this->run(
                    fn () => $this->entry->reject($code, is_string($input['note'] ?? null) ? $input['note'] : ''),
                    [self::STEP_STATUS, 'rejected'],
                ),
                default    => throw TeamEntryException::invalidCode(),
            };

            return ['code' => $code, 'step' => $step, 'notice' => $notice, 'error' => null];
        } catch (\PDOException $e) {
            // The page needs no login: database details (SQLSTATE, table and key
            // names) belong in the log, not on the screen.
            ($this->log)('Team entry, action ' . $action . ': ' . $e->getMessage());

            return ['code' => $code, 'step' => $this->errorStep($method, $game), 'notice' => null, 'error' => $this->t->t(self::DATABASE_ERROR)];
        } catch (TeamEntryException $e) {
            return ['code' => $code, 'step' => $this->errorStep($method, $game), 'notice' => null, 'error' => $this->t->error($e)];
        } catch (\RuntimeException $e) {
            return ['code' => $code, 'step' => $this->errorStep($method, $game), 'notice' => null, 'error' => $e->getMessage()];
        }
    }

    // --- Admin ------------------------------------------------------------------

    /**
     * Card on the virtual score sheet, empty if the fixture is not open to
     * team entry.
     */
    public function adminFixtureCard(int $fixtureId, string $csrfField, string $baseUrl): string
    {
        $state = $this->entry->adminState($fixtureId);
        if ($state === null) {
            return '';
        }

        $fixture = $state['fixture'];
        $sides = $state['sides'];
        $closed = $sides['home']->closed || $sides['away']->closed || $fixture['status'] === 'complete';
        $data = ['fixture_id' => $fixtureId, 'closed' => $closed, 'net_count' => count(array_filter($state['games'], static fn (array $g): bool => $g['net'] !== null))];

        foreach (['home', 'away'] as $side) {
            $own = $sides[$side];
            $opponent = $sides[TeamEntrySide::opposite($side)];
            $url = null;
            $qr = null;
            if (! $closed) {
                $code = $this->entry->codeFor($fixtureId, $side);
                if ($code !== null) {
                    $url = self::entryUrl($baseUrl, $code);
                    $qr = $this->qrCode->svg($url);
                }
            }

            $status = [];
            if ($fixture[$side]['is_ghost_team']) {
                $status[] = 'ghost';
            } elseif ($fixture[TeamEntrySide::opposite($side)]['is_ghost_team']) {
                $status[] = $own->submitted ? 'sent_final' : 'entering';
            } else {
                $status[] = $own->submitted ? 'sent' : 'entering';
                $status[] = $opponent->confirmed ? 'confirmed' : ($own->submitted ? 'waiting' : 'not_yet');
            }

            $data[$side] = [
                'name'      => $fixture[$side]['team_name'],
                'status'    => implode(' · ', array_map(fn (string $s): string => $this->t->t('team_entry.admin.status.' . $s), $status)),
                'rejection' => $own->rejectionNote,
                'url'       => $url,
                'qr'        => $qr,
            ];
        }

        return $this->view->renderAdminFixture($data, $csrfField);
    }

    public function adminSeasonCard(?int $seasonId, string $csrfField): string
    {
        if ($seasonId === null) {
            return '';
        }

        return $this->view->renderAdminSeason(
            $seasonId,
            $this->entry->isEnabled($seasonId),
            $this->entry->activeFixtures($seasonId),
            $csrfField,
        );
    }

    /**
     * Admin actions: `team-entry` (switch), `team-entry-take-over`,
     * `team-entry-codes`.
     *
     * @param array<string, mixed> $input
     */
    public function adminAction(string $action, array $input): ActionResult
    {
        try {
            switch ($action) {
                case 'team-entry':
                    $seasonId = (int) ($input['season_id'] ?? 0);
                    $enabled = ($input['enabled'] ?? '') === '1';
                    $this->entry->setEnabled($seasonId, $enabled);

                    return ActionResult::success($this->t->t($enabled ? 'team_entry.admin.enabled' : 'team_entry.admin.disabled'));
                case 'team-entry-take-over':
                    $fixtureId = (int) ($input['fixture_id'] ?? 0);
                    $count = $this->entry->takeOver($fixtureId);

                    return ActionResult::success(
                        $this->t->t('team_entry.admin.taken_over', ['count' => $count]),
                        '/admin/results/score-sheet?fixture=' . $fixtureId,
                    );
                case 'team-entry-codes':
                    $fixtureId = (int) ($input['fixture_id'] ?? 0);
                    $this->entry->renewCodes($fixtureId);

                    return ActionResult::success(
                        $this->t->t('team_entry.admin.codes_renewed'),
                        '/admin/results/score-sheet?fixture=' . $fixtureId,
                    );
            }
        } catch (AccessDeniedException $e) {
            return ActionResult::error($this->t->t('common.no_permission'));
        } catch (TeamEntryException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (Throwable $e) {
            return ActionResult::error($e->getMessage());
        }

        return ActionResult::error($this->t->t('common.unknown_action'));
    }

    /** Address of the team page that the QR code carries. */
    public static function entryUrl(string $baseUrl, string $code): string
    {
        return rtrim($baseUrl, '/') . '/entry?code=' . rawurlencode($code);
    }

    /** Address of a step of the team page. */
    public static function stepUrl(string $code, int $step, ?string $notice = null): string
    {
        return '/entry?code=' . rawurlencode($code) . '&step=' . $step
            . ($notice !== null ? '&notice=' . rawurlencode($notice) : '');
    }

    // --- Internals --------------------------------------------------------------

    /**
     * Saves one game and returns the next step; going forward checks the game.
     *
     * @param array<string, mixed> $input
     */
    private function save(string $code, int $game, array $input): int
    {
        $this->entry->saveGame(
            $code,
            $game,
            is_array($input['lineup'] ?? null) ? $input['lineup'] : [],
            is_array($input['nets'] ?? null) ? $input['nets'] : [],
        );

        $target = filter_var($input['next'] ?? null, FILTER_VALIDATE_INT);
        $target = is_int($target) && $target >= 1 && $target <= self::STEP_STATUS ? $target : $game;
        if ($target > $game) {
            $this->entry->checkGame($code, $game);
        }

        return $target;
    }

    /** @param array{0: int, 1: string} $result */
    private function run(callable $step, array $result): array
    {
        $step();

        return $result;
    }

    private function errorStep(?string $method, ?int $game): ?int
    {
        return match ($method) {
            'save'               => $game !== null && in_array($game, self::GAMES, true) ? $game : null,
            'submit', 'withdraw' => self::STEP_OVERVIEW,
            'confirm', 'reject'  => self::STEP_STATUS,
            default              => null,
        };
    }

    /**
     * View data of the team page.
     *
     * @return array<string, mixed>
     */
    private function viewData(TeamEntrySheet $sheet, ?int $requestedStep): array
    {
        $fixture = $sheet->fixture;
        $own = $sheet->ownTeam();
        $opponent = $sheet->opponentTeam();
        $ghost = $sheet->ghostOpponent();
        $matchdayId = $fixture['matchday_id'];

        $stats = [];
        foreach ([$own, $opponent] as $team) {
            foreach (array_keys($team['players']) as $playerId) {
                $stats[$playerId] = $this->stats->stats($matchdayId, $playerId);
            }
        }

        // Games the own team has completed (each one passes the check of going forward).
        $complete = [];
        foreach (self::GAMES as $game) {
            try {
                $this->entry->checkGame($sheet->code, $game);
                $complete[$game] = true;
            } catch (TeamEntryException) {
                $complete[$game] = false;
            }
        }

        $ownEntries = $sheet->participation($own['team_season_id']);
        $ownPositions = $this->entry->blindPositions($sheet, $own);
        $ownTeam = $this->teamData($own, $ownEntries, $ownPositions, $stats);

        if ($ghost) {
            $opponentTeam = $this->ghostTeam($opponent['team_name'], (int) $fixture['ghost_score']);
        } else {
            $opponentEntries = $sheet->participation($opponent['team_season_id']);
            $opponentTeam = $this->teamData($opponent, $opponentEntries, $this->entry->blindPositions($sheet, $opponent), $stats);
        }

        $calculated = $this->calculator->calculate(
            ['own' => ['rows' => $ownTeam['calculator']], 'opponent' => ['rows' => $opponentTeam['calculator']]],
            $this->stats->formula($fixture['season_id']),
            $this->stats->pointsRules($fixture['season_id']),
        );

        $step = $this->step($sheet, $requestedStep, $complete);
        $gameData = null;
        if ($step <= 3) {
            $gameData = $this->gameData($sheet, $step, $own, $ownEntries, $stats);
        }

        $title = $this->t->t('common.matchday_number', ['number' => $fixture['matchday_number']])
            . ' · ' . $this->format->date($fixture['date'], true)
            . ($fixture['lanes'] !== null ? ' · ' . $this->t->t('common.lanes', ['lanes' => $fixture['lanes']]) : '');

        return [
            'code'       => $sheet->code,
            'title'      => $title,
            'step'       => $step,
            'complete'   => $complete,
            'own'        => ['name' => $own['team_name']] + $ownTeam,
            'opponent'   => ['name' => $opponent['team_name']] + $opponentTeam,
            'calculated' => $calculated,
            'game'       => $gameData,
            'state'      => [
                'ghost'              => $ghost,
                'finished'           => $sheet->finished,
                'final'              => $fixture['status'] === 'complete' && $sheet->finished,
                'can_edit'           => $sheet->canEdit(),
                'can_submit'         => $sheet->canSubmit(),
                'can_withdraw'       => $sheet->canWithdraw(),
                'can_confirm'        => $sheet->canConfirm(),
                'own_submitted'      => $sheet->own->submitted,
                'own_confirmed'      => $sheet->own->confirmed,
                'opponent_submitted' => $sheet->opponent->submitted,
                'opponent_confirmed' => $sheet->opponent->confirmed,
                'own_rejection'      => $sheet->own->rejectionNote,
                'opponent_rejection' => $sheet->opponent->rejectionNote,
            ],
        ];
    }

    /**
     * The step to show: the requested one if it makes sense, otherwise the
     * first open game, the overview or – once sent – the confirmation.
     *
     * @param array<int, bool> $complete
     */
    private function step(TeamEntrySheet $sheet, ?int $requested, array $complete): int
    {
        $last = $sheet->ghostOpponent() ? self::STEP_OVERVIEW : self::STEP_STATUS;
        if ($sheet->finished) {
            return $last;
        }
        if ($requested !== null && $requested >= 1 && $requested <= $last) {
            // Games can only be changed until the entry is sent.
            return $requested <= 3 && ! $sheet->canEdit() ? self::STEP_OVERVIEW : $requested;
        }
        if (! $sheet->canEdit()) {
            return $last;
        }
        foreach (self::GAMES as $game) {
            if (! $complete[$game]) {
                return $game;
            }
        }

        return self::STEP_OVERVIEW;
    }

    /**
     * One game of the own team for the wizard: every roster player with mark,
     * net and what is possible for him; a game not entered yet takes over the
     * lineup of the game before.
     *
     * @param array{team_season_id: int, players: array<int, string>}    $team
     * @param array<int, array<int, array{is_blind: bool, net: int|null}>> $entries
     * @param array<int, array<string, mixed>>                              $stats
     *
     * @return array<string, mixed>
     */
    private function gameData(TeamEntrySheet $sheet, int $game, array $team, array $entries, array $stats): array
    {
        $entered = array_filter($entries, static fn (array $games): bool => isset($games[$game])) !== [];
        $takenOver = ! $entered && $game > 1 && $sheet->canEdit();

        $virtual = $entries;
        if ($takenOver) {
            foreach ($entries as $playerId => $games) {
                if (isset($games[$game - 1])) {
                    $virtual[$playerId][$game] = ['is_blind' => $games[$game - 1]['is_blind'], 'net' => null];
                }
            }
        }

        $rows = [];
        foreach ($team['players'] as $playerId => $name) {
            $games = $virtual[$playerId] ?? [];
            $entry = $games[$game] ?? null;
            $earlier = array_filter($games, static fn (int $number): bool => $number < $game, ARRAY_FILTER_USE_KEY);
            $realBefore = array_filter($earlier, static fn (array $e): bool => ! $e['is_blind']) !== [];
            $blindBefore = array_filter($earlier, static fn (array $e): bool => $e['is_blind']) !== [];
            $left = $realBefore && ! isset($games[$game - 1]);

            $rows[] = [
                'player_id' => $playerId,
                'name'      => $name,
                'average'   => $stats[$playerId]['average'],
                'hdc'       => $stats[$playerId]['hdc'],
                'mark'      => $entry === null ? '' : ($entry['is_blind'] ? 'blind' : 'plays'),
                'net'       => $entry !== null && ! $entry['is_blind'] ? $entry['net'] : null,
                // A player who left or dropped out does not come back (R27.5).
                'can_play'  => ! $blindBefore && ! $left,
                'can_blind' => $realBefore && isset($games[$game - 1]),
                'status'    => $blindBefore ? 'dropped_out' : ($left ? 'substituted' : null),
            ];
        }

        $positions = $this->entry->blindPositions($sheet, $team, $virtual);
        $playing = count(array_filter($rows, static fn (array $r): bool => $r['mark'] === 'plays'));
        $fill = [];
        foreach ($positions as $position) {
            if ($position->gameNumber === $game) {
                $fill[] = $this->positionLabel($position, $team['players'], $stats);
            }
        }

        return [
            'number'     => $game,
            'taken_over' => $takenOver,
            'rows'       => $rows,
            'playing'    => $playing,
            'fill'       => $fill,
        ];
    }

    /**
     * Rows of a team for the overview and the calculator: entered games and
     * positions filled with blind values.
     *
     * @param array{team_season_id: int, players: array<int, string>}    $team
     * @param array<int, array<int, array{is_blind: bool, net: int|null}>> $entries
     * @param list<BlindPosition>                                           $positions
     * @param array<int, array<string, mixed>>                              $stats
     *
     * @return array{rows: list<array<string, mixed>>, calculator: array<string, array<string, mixed>>}
     */
    private function teamData(array $team, array $entries, array $positions, array $stats): array
    {
        $rows = [];
        foreach ($entries as $playerId => $games) {
            $rows['p' . $playerId] = $this->row($team['players'][$playerId] ?? $this->t->t('common.player_number', ['number' => $playerId]), $playerId, $stats[$playerId] ?? null, false);
            foreach ($games as $game => $entry) {
                if (! $entry['is_blind']) {
                    $rows['p' . $playerId]['games'][$game] = ['net' => $entry['net'], 'blind' => false];
                }
            }
        }

        foreach ($positions as $position) {
            $key = match ($position->kind) {
                BlindPosition::OWN        => 'p' . $position->playerId,
                BlindPosition::SUBSTITUTE => 's' . $position->playerId,
                default                   => 'b' . $position->slot,
            };
            if (! isset($rows[$key])) {
                $playerId = $position->playerId;
                // A blind slot plays like a ghost player: no handicap (R27.18).
                $rows[$key] = $playerId === null
                    ? $this->row($this->t->t('team_entry.table.slot'), null, ['hdc' => 0], true)
                    : $this->row($team['players'][$playerId] ?? $this->t->t('common.player_number', ['number' => $playerId]), $playerId, $stats[$playerId] ?? null, false);
                $rows[$key]['blind_row'] = $position->kind !== BlindPosition::OWN;
            }
            $rows[$key]['games'][$position->gameNumber] = ['net' => $position->net, 'blind' => true, 'from_evening' => $position->fromEvening];
        }

        $calculator = [];
        foreach ($rows as $key => $row) {
            $values = [];
            $blind = [];
            foreach (self::GAMES as $game) {
                $values[$game] = $row['games'][$game]['net'] ?? null;
                $blind[$game] = $row['games'][$game]['blind'] ?? false;
            }
            $calculator[$key] = [
                'hdc'         => $row['hdc'],
                'first_value' => $row['first_matchday'],
                'blind'       => $row['blind_row'],
                'values'      => $values,
                'blind_games' => $blind,
            ];
        }

        return ['rows' => $rows, 'calculator' => $calculator];
    }

    /**
     * @param array<string, mixed>|null $stats
     *
     * @return array<string, mixed>
     */
    private function row(string $name, ?int $playerId, ?array $stats, bool $slot): array
    {
        return [
            'name'           => $name,
            'player_id'      => $playerId,
            'slot'           => $slot,
            'average'        => $stats['average'] ?? null,
            'hdc'            => $stats['hdc'] ?? null,
            'first_matchday' => $stats['first_matchday'] ?? false,
            'blind_row'      => false,
            'games'          => [],
        ];
    }

    /**
     * The ghost team: four ghost players with the season's ghost score per
     * game and HDC 0 (R20).
     *
     * @return array{rows: array<string, array<string, mixed>>, calculator: array<string, array<string, mixed>>}
     */
    private function ghostTeam(string $name, int $ghostScore): array
    {
        $rows = [];
        $calculator = [];
        for ($slot = 1; $slot <= 4; $slot++) {
            $games = [];
            foreach (self::GAMES as $game) {
                $games[$game] = ['net' => $ghostScore, 'blind' => false];
            }
            $rows['g' . $slot] = [
                'name' => $this->t->t('team_entry.ghost_player', ['number' => $slot]), 'player_id' => null, 'slot' => false, 'average' => null,
                'hdc' => 0, 'first_matchday' => false, 'blind_row' => false, 'games' => $games,
            ];
            $calculator['g' . $slot] = [
                'hdc' => 0, 'first_value' => false, 'blind' => false,
                'values' => array_fill(1, 3, $ghostScore), 'blind_games' => array_fill(1, 3, false),
            ];
        }

        return ['rows' => $rows, 'calculator' => $calculator];
    }

    /**
     * Text for a position filled automatically.
     *
     * @param array<int, string>               $players
     * @param array<int, array<string, mixed>> $stats
     *
     * @return array{kind: string, text: string, net: int|null, from_evening: bool, ghost_value: bool}
     */
    private function positionLabel(BlindPosition $position, array $players, array $stats): array
    {
        $name = $position->playerId !== null ? ($players[$position->playerId] ?? $this->t->t('common.player_number', ['number' => $position->playerId])) : '';
        $key = match ($position->kind) {
            BlindPosition::OWN        => 'team_entry.position.own',
            BlindPosition::SUBSTITUTE => $position->ghostValue ? 'team_entry.position.substitute_new' : 'team_entry.position.substitute',
            default                   => 'team_entry.position.slot',
        };
        $text = $this->t->t($key, ['player' => $name]);

        return ['kind' => $position->kind, 'text' => $text, 'net' => $position->net, 'from_evening' => $position->fromEvening, 'ghost_value' => $position->ghostValue];
    }
}
