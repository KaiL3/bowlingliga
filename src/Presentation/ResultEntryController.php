<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\ResultEntry\BlindPlayerOrder;
use Bowlingliga\Application\ResultEntry\EnteredGame;
use Bowlingliga\Application\ResultEntry\EnteredResults;
use Bowlingliga\Application\ResultEntry\OriginalScoreSheetStorage;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Application\ResultEntry\ScoreSheetOverview;
use Bowlingliga\Application\ResultEntry\ScoreSheetTeam;
use Bowlingliga\Application\ResultEntry\VirtualScoreSheetService;
use Bowlingliga\Domain\BlindPlayerService;
use Bowlingliga\Domain\Ocr\OcrException;
use Bowlingliga\Domain\Ocr\UploadedFile;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;

/**
 * Controller of the result entry in the admin area (task 35.3, R28.12).
 *
 * Wires the use cases of tasks 22, 24 and 25 with the forms of the admin
 * area:
 * - overview of all fixtures of a season with completion status
 *   (R30.1–R30.3),
 * - virtual score sheet per team with one row of 3 net fields for every
 *   roster player, blind flag per game and prefill from stored results
 *   (R22.1, R27, R30.4–R30.13). A missing player is replaced by a roster
 *   player who does not play, marked blind in their own row (R27.4); if no
 *   roster player is left, by a blind slot that plays like a ghost player
 *   (ghost score, no handicap, R27.18).
 *   Players are substituted between games, so more than 4 players can take
 *   part; the lineup of the evening follows from the entered results
 *   (R21.2, R27.17). Saving as
 *   draft or confirming including cache invalidation, handicap freezing and
 *   played flag (R22.6, R24.1, R30.14–R30.18, R35.4),
 * - upload of the original score sheet as immutable evidence (R3.15, R3.16).
 *   Reading it automatically via OCR is deferred: Tesseract does not
 *   recognize the handwritten results of the score sheets (decision of
 *   12.10.2026); the values are entered manually.
 *
 * The ghost team plays with a fixed lineup and a fixed net = ghost score
 * (R20.1, R20.6); the controller adds these results when saving. The former
 * separate lineup step ({@see \Bowlingliga\Application\LineupManagement})
 * is no longer wired. CSRF
 * protection (R22.5) is enforced by the calling front controller.
 */
final class ResultEntryController
{
    public const PAGES = ['home', 'away'];

    /** Players on the lanes per team and game (R21.2, R27.1). */
    private const PLAYERS_PER_GAME = 4;

    /** At most 3 blind players per game (R27.2). */
    private const MAX_BLIND = 3;

    public function __construct(
        private readonly ScoreSheetOverview $overview,
        private readonly VirtualScoreSheetService $scoreSheet,
        private readonly ResultRecording $storage,
        private readonly BlindPlayerOrder $blindPlayers,
        private readonly OriginalScoreSheetStorage $originalStorage,
        private readonly EntryData $data,
        private readonly ResultEntryView $view,
        private readonly PlayerStatsReader $stats,
        private readonly ScoreSheetCalculator $calculator = new ScoreSheetCalculator(),
        private readonly BlindPlayerService $blindPlayer = new BlindPlayerService(),
        private readonly Translator $t = new Translator(),
    ) {
    }

    /**
     * Overview of all fixtures of the season with completion status.
     */
    public function overview(?int $seasonId): string
    {
        if ($seasonId === null) {
            return $this->view->renderOverview([]);
        }

        return $this->view->renderOverview($this->overview->showOverview($seasonId));
    }

    /**
     * Renders the virtual score sheet of a fixture.
     *
     * @param array<string, mixed>|null $input last submitted results (after an
     *        error), take precedence over the stored values
     */
    public function scoreSheetPage(
        string $csrfField,
        int $fixtureId,
        ?array $input = null,
        ?string $message = null,
        ?string $error = null,
    ): string {
        $fixture = $this->data->teamFixture($fixtureId);
        if ($fixture === null || $fixture['home'] === null || $fixture['away'] === null) {
            return $this->view->renderMissingFixture();
        }

        $virtual = $this->scoreSheet->openVirtualScoreSheet($fixtureId);

        $teams = [];
        foreach (self::PAGES as $page) {
            $teams[$page] = $this->buildTeamView(
                $fixture,
                $page,
                $page === 'home' ? $virtual->home : $virtual->away,
                $input,
            );
        }

        // Derived values (gross, sums, points) as on the paper score sheet.
        $formula = $this->stats->formula($fixture['season_id']);
        $points = $this->stats->pointsRules($fixture['season_id']);
        $calculated = $this->calculator->calculate(
            array_map(static fn (array $team): array => ['rows' => $team['rows']], $teams),
            $formula,
            $points,
        );

        $rework = false;
        foreach ($teams as $team) {
            foreach ($team['rows'] as $row) {
                foreach ($row['fields'] as $field) {
                    $rework = $rework || $field['check'];
                }
            }
        }

        return $this->view->renderScoreSheet(
            $csrfField,
            $fixture,
            $teams,
            $calculated,
            $formula,
            $this->data->originalScoreSheet($fixtureId),
            $rework,
            $message,
            $error,
            $points,
        );
    }

    /**
     * Saves the results as draft (`action=draft`) or confirms them
     * (`action=confirm`, R30.14–R30.18).
     *
     * Form fields: `net[page][player id][game]` and `blind[page][player
     * id][game]`.
     *
     * @param array<string, mixed> $input
     */
    public function saveResults(array $input): ActionResult
    {
        return $this->perform(function () use ($input): ActionResult {
            $fixture = $this->fixture($input);
            $confirm = ($input['action'] ?? '') === 'confirm';

            $games       = [];
            $ghostTeams  = [];
            $teamNames   = [];
            $playerNames = [];
            foreach (self::PAGES as $page) {
                $team   = $fixture[$page];
                $teamId = $team['team_season_id'];
                $teamNames[$teamId] = $team['team_name'];

                if ($team['is_ghost_team']) {
                    // Fixed lineup and fixed net = ghost score (R20.1, R20.6).
                    $ghostTeams[] = $teamId;
                    for ($slot = 1; $slot <= self::PLAYERS_PER_GAME; $slot++) {
                        for ($num = 1; $num <= 3; $num++) {
                            $games[] = EnteredGame::ghost($teamId, $slot, $num, $fixture['ghost_score']);
                        }
                    }
                    continue;
                }

                $names = [];
                foreach ($this->data->roster($teamId) as $player) {
                    $names[$player['player_id']] = $player['name'];
                }

                foreach ($this->readPlayerValues($input, $page) as [$playerId, $num, $raw]) {
                    $names[$playerId] ??= $this->data->playerNames([$playerId])[$playerId] ?? $this->t->t('common.player_number', ['number' => $playerId]);
                    $games[] = EnteredGame::player(
                        $teamId,
                        $playerId,
                        $num,
                        $this->net($raw, $team['team_name'], $names[$playerId], $num),
                        $this->isBlind($input, $page, $playerId, $num),
                    );
                }

                // Blind slots without a player of their own, like a ghost player (R27.18).
                foreach ($this->readSlotValues($input, $page) as [$slot, $num, $raw]) {
                    $games[] = EnteredGame::blindSlot($teamId, $slot, $num, $this->net($raw, $team['team_name'], 'Blindplatz ' . $slot, $num));
                }

                $playerNames += $names;
            }

            $results = new EnteredResults($fixture['id'], $fixture['matchday_id'], $games, $ghostTeams, $teamNames, $playerNames);

            if ($confirm) {
                $this->storage->confirmAndSave($results);

                // Correction of a complete fixture: on request a preview of the
                // handicap chain recalculation from this matchday (R12.17, R12.18).
                if ($fixture['status'] === 'complete' && ($input['recalculate_chain'] ?? '') === '1') {
                    return ActionResult::success(
                        $this->t->t('results.correction_saved'),
                        '/admin/results/handicap-chain?fixture=' . $fixture['id'],
                    );
                }

                return ActionResult::success(
                    $this->t->t('results.confirmed')
                );
            }

            $this->storage->saveDraft($results);

            return ActionResult::success($this->t->t('results.draft_saved'));
        });
    }

    /**
     * Preview of the handicap chain recalculation from the matchday of the
     * fixture (R12.18); the frozen handicaps stay unchanged (R12.19).
     */
    public function handicapChainPage(string $csrfField, int $fixtureId, ?string $error = null): string
    {
        $fixture = $this->data->teamFixture($fixtureId);
        if ($fixture === null || $fixture['home'] === null || $fixture['away'] === null) {
            return $this->view->renderMissingFixture();
        }

        try {
            $preview = $this->storage->previewHandicapChain($fixture['matchday_id']);
        } catch (AccessDeniedException | \RuntimeException $e) {
            return $this->view->renderHandicapChainError($fixture, $this->t->error($e));
        }

        $playerIds = array_map(static fn ($a): int => $a->playerId, $preview->changesOnly());

        return $this->view->renderHandicapChain(
            $csrfField,
            $fixture,
            $preview,
            $this->data->seasonMatchdays($fixture['season_id']),
            $this->data->playerNames($playerIds),
            $error,
        );
    }

    /**
     * Applies the confirmed recalculation of the handicap chain (R12.18);
     * without explicit confirmation everything stays unchanged (R12.19).
     *
     * @param array<string, mixed> $input
     */
    public function applyHandicapChain(array $input): ActionResult
    {
        return $this->perform(function () use ($input): ActionResult {
            $fixture = $this->fixture($input);
            if (($input['confirmed'] ?? '') !== '1') {
                return ActionResult::error($this->t->t('results.confirm_checked'));
            }

            $fingerprint = $input['fingerprint'] ?? '';
            $preview = $this->storage->applyHandicapChain(
                $fixture['matchday_id'],
                is_string($fingerprint) ? $fingerprint : '',
            );

            $quantity = count(array_filter(
                $preview->changesOnly(),
                static fn ($a): bool => $a->newHandicap !== null,
            ));

            return ActionResult::success($this->t->t(
                $quantity === 1 ? 'results.chain_applied_one' : 'results.chain_applied',
                ['number' => $fixture['matchday_number'], 'count' => $quantity],
            ));
        });
    }

    /**
     * Removes all results of the fixture (R24.4).
     *
     * @param array<string, mixed> $input
     */
    public function removeResults(array $input): ActionResult
    {
        return $this->perform(function () use ($input): ActionResult {
            $fixture = $this->fixture($input);
            $this->storage->removeResults($fixture['id'], $fixture['matchday_id']);

            return ActionResult::success($this->t->t('results.removed'));
        });
    }

    /**
     * Stores an uploaded original score sheet unchanged as evidence of the
     * fixture (R3.1, R3.2, R3.15, R3.16). It is not read automatically (OCR
     * deferred, see R3).
     *
     * @param array<string, mixed> $input Form data (`teampaarung_id`)
     * @param array<string, mixed> $file  Entry from $_FILES
     */
    public function saveOriginal(array $input, array $file): ActionResult
    {
        return $this->perform(function () use ($input, $file): ActionResult {
            $fixture = $this->fixture($input);

            $uploadError = $this->checkUpload($file);
            if ($uploadError !== null) {
                return ActionResult::error($uploadError);
            }

            // The file type is determined by the content, not by what the browser claims.
            $content = (string) file_get_contents((string) $file['tmp_name']);
            $mime   = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';

            try {
                $this->originalStorage->save(
                    $fixture['id'],
                    new UploadedFile($content, (string) ($file['name'] ?? 'spielzettel'), $mime),
                );
            } catch (OcrException $e) {
                // Unsupported format or too large (R3.2).
                return ActionResult::error($this->t->error($e));
            }

            return ActionResult::success($this->t->t('results.original_saved'));
        });
    }

    /**
     * Builds the view data of a team for the virtual score sheet: one row per
     * roster player – the games they played, empty for the others, blind for
     * a player serving as blind player (R21.2, R27.4, R27.17).
     *
     * Players who are no longer on the roster but have results in this
     * fixture keep their row. Once players have been entered, the order of
     * the possible blind players is added as a hint (R27.4).
     *
     * @param array<string, mixed>      $fixture
     * @param array<string, mixed>|null $input
     *
     * @return array<string, mixed>
     */
    private function buildTeamView(array $fixture, string $page, ScoreSheetTeam $virtual, ?array $input): array
    {
        $team = $fixture[$page];

        if ($team['is_ghost_team']) {
            // Fixed lineup: per row and game net = ghost score, no handicap
            // (R20.1, R20.2).
            $rows = [];
            for ($slot = 1; $slot <= self::PLAYERS_PER_GAME; $slot++) {
                $rows['g' . $slot] = [
                    'kind' => 'ghost', 'name' => 'Geisterspieler', 'average' => null, 'games' => null, 'hdc' => 0,
                    'first_value' => false, 'blind' => false, 'blind_games' => [], 'reference' => null,
                    'values' => [1 => $fixture['ghost_score'], 2 => $fixture['ghost_score'], 3 => $fixture['ghost_score']],
                    'fields' => array_fill(1, 3, ['value' => (string) $fixture['ghost_score'], 'blind' => false, 'check' => false]),
                ];
            }

            return $team + ['roster' => [], 'rows' => $rows, 'blind_order' => [], 'ghost_score' => $fixture['ghost_score']];
        }

        $roster = $this->data->roster($team['team_season_id']);

        // Stored rows by player; every player has one row (also as blind player).
        $storedPlayers = [];
        foreach ($virtual->rows as $row) {
            if ($row->playerId !== null) {
                $storedPlayers[$row->playerId] ??= $row;
            }
        }

        $players = [];
        foreach ($roster as $player) {
            $players[$player['player_id']] = $player['name'];
        }
        foreach ($storedPlayers as $playerId => $row) {
            $players[$playerId] ??= $row->playerLabel ?? $this->t->t('common.player_number', ['number' => $playerId]);
        }

        $rows         = [];
        $participants = [];
        foreach ($players as $playerId => $name) {
            $stored = [];
            foreach (($storedPlayers[$playerId] ?? null)?->fields ?? [] as $field) {
                $stored[$field->gameNumber] = $field;
            }

            $values = [];
            $blindGames = [];
            for ($num = 1; $num <= 3; $num++) {
                $values[$num] = $input !== null
                    ? trim((string) ($input['net'][$page][$playerId][$num] ?? ''))
                    : (($stored[$num] ?? null)?->net !== null ? (string) $stored[$num]->net : '');
                $blindGames[$num] = $input !== null
                    ? $this->isBlind($input, $page, $playerId, $num)
                    : (bool) (($stored[$num] ?? null)?->isBlind);
                if ($values[$num] !== '' && ! $blindGames[$num]) {
                    $participants[$playerId] = $playerId;
                }
            }

            $rows['p' . $playerId] = $this->buildRow(
                $name,
                $this->stats->stats($fixture['matchday_id'], $playerId),
                $values,
                $blindGames,
            ) + ['player_id' => $playerId];
        }

        // Order of the blind players among those who do not play (R27.4);
        // only meaningful once players have been entered.
        $blindOrder = [];
        if ($participants !== []) {
            foreach ($this->blindPlayers->candidates($team['team_season_id'], $fixture['matchday_id'], array_values($participants)) as $candidate) {
                $blindOrder[] = ['name' => $players[$candidate['player_id']] ?? 'Spieler ' . $candidate['player_id'], 'games' => $candidate['games']];
            }
        }

        $rows += $this->slotRows($fixture, $page, $team, $virtual, $input);

        return $team + [
            'roster'      => $roster,
            'rows'        => $rows,
            'blind_order' => array_slice($blindOrder, 0, self::MAX_BLIND),
        ];
    }

    /**
     * Blind slots without a player of their own (R27.18): the stored ones
     * and – if the roster has fewer than 4 players – as many as are missing.
     * A blind slot plays like a ghost player: prefilled with the season's
     * ghost score (editable), no handicap.
     *
     * @param array<string, mixed>      $fixture
     * @param array<string, mixed>      $team
     * @param array<string, mixed>|null $input
     *
     * @return array<string, array<string, mixed>>
     */
    private function slotRows(array $fixture, string $page, array $team, ScoreSheetTeam $virtual, ?array $input): array
    {
        $stored = [];
        foreach ($virtual->rows as $row) {
            if ($row->playerId === null && $row->isBlindRow) {
                $stored[count($stored) + 1] = $row;
            }
        }
        $count = max(count($stored), self::PLAYERS_PER_GAME - count($this->data->roster($team['team_season_id'])));
        if ($input !== null && is_array($input['slot'][$page] ?? null)) {
            $count = max($count, count($input['slot'][$page]));
        }

        $ghostScore = (int) $fixture['ghost_score'];
        $noPlayer = ['average' => null, 'games' => 0, 'hdc' => 0, 'frozen' => false, 'first_matchday' => false];
        $slots = [];
        for ($slot = 1; $slot <= min($count, self::MAX_BLIND); $slot++) {
            $row = $stored[$slot] ?? null;
            $values = [];
            for ($num = 1; $num <= 3; $num++) {
                $storedField = null;
                foreach ($row?->fields ?? [] as $field) {
                    if ($field->gameNumber === $num) {
                        $storedField = $field;
                    }
                }
                $values[$num] = match (true) {
                    $input !== null => trim((string) ($input['slot'][$page][$slot][$num] ?? '')),
                    $row !== null   => $storedField?->net !== null ? (string) $storedField->net : '',
                    default         => (string) $ghostScore,
                };
            }

            $built = $this->buildRow($this->t->t('team_entry.table.slot'), $noPlayer, $values, [1 => true, 2 => true, 3 => true]);
            $built['reference'] = $ghostScore;
            foreach ($built['fields'] as $num => $field) {
                $built['fields'][$num]['check'] = $built['values'][$num] !== null && $built['values'][$num] !== $ghostScore;
            }
            $slots['b' . $slot] = [
                'kind'        => 'slot',
                'slot'        => $slot,
                'player_id'   => null,
                'games'       => null,
                'first_value' => false,
                'blind'       => true,
            ] + $built;
        }

        return $slots;
    }
    /**
     * View data of one row: statistics of the player, fields with blind flag
     * and review need.
     *
     * The blind reference is the net average of the row's player − 10 (R27.6,
     * R27.8, R27.9); without an average there is no reference (R27.16). Only a
     * deviating blind game needs review (R27.11).
     *
     * @param array{average: float|null, games: int, hdc: int|null, frozen: bool, first_matchday: bool} $stats
     * @param array<int, string> $values     entered values by game number
     * @param array<int, bool>   $blindGames blind flags by game number
     *
     * @return array<string, mixed>
     */
    private function buildRow(string $name, array $stats, array $values, array $blindGames): array
    {
        $reference = in_array(true, $blindGames, true) && $stats['average'] !== null
            ? $this->blindPlayer->derivedBlindNet($stats['average'])
            : null;

        $fields   = [];
        $numerals = [];
        foreach ($values as $num => $value) {
            $numeral = ctype_digit($value) && (int) $value <= 300 ? (int) $value : null;

            $numerals[$num] = $numeral;
            $fields[$num]   = [
                'value' => $value,
                'blind' => $blindGames[$num],
                'check' => $blindGames[$num] && $reference !== null && $numeral !== null && $numeral !== $reference,
            ];
        }

        return [
            'kind'        => 'player',
            'name'        => $name,
            'average'     => $stats['average'],
            'games'       => $stats['games'],
            'hdc'         => $stats['hdc'],
            'first_value' => $stats['first_matchday'],
            'blind'       => false,
            'blind_games' => $blindGames,
            'reference'   => $reference,
            'values'      => $numerals,
            'fields'      => $fields,
        ];
    }

    /**
     * Reads the values entered in the player rows of one side, in form order;
     * empty fields are dropped.
     *
     * @param array<string, mixed> $input
     *
     * @return list<array{0: int, 1: int, 2: string}> player id, game, raw value
     */
    private function readPlayerValues(array $input, string $page): array
    {
        $rows = $input['net'][$page] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $values = [];
        foreach ($rows as $playerId => $games) {
            $id = filter_var($playerId, FILTER_VALIDATE_INT);
            if (! is_int($id) || $id <= 0 || ! is_array($games)) {
                continue;
            }
            for ($num = 1; $num <= 3; $num++) {
                $raw = trim((string) ($games[$num] ?? ''));
                if ($raw !== '') {
                    $values[] = [$id, $num, $raw];
                }
            }
        }

        return $values;
    }

    /**
     * Reads the values of the blind slots of one side (`slot[page][slot][game]`);
     * empty fields are dropped.
     *
     * @param array<string, mixed> $input
     *
     * @return list<array{0: int, 1: int, 2: string}> slot, game, raw value
     */
    private function readSlotValues(array $input, string $page): array
    {
        $slots = $input['slot'][$page] ?? null;
        if (! is_array($slots)) {
            return [];
        }

        $values = [];
        foreach ($slots as $slot => $games) {
            $number = filter_var($slot, FILTER_VALIDATE_INT);
            if (! is_int($number) || $number < 1 || $number > self::MAX_BLIND || ! is_array($games)) {
                continue;
            }
            for ($num = 1; $num <= 3; $num++) {
                $raw = trim((string) ($games[$num] ?? ''));
                if ($raw !== '') {
                    $values[] = [$number, $num, $raw];
                }
            }
        }

        return $values;
    }

    /**
     * Converts an entered value into a net result.
     *
     * @throws ValidationException for non-integer values (R22.4).
     */
    private function net(string $raw, string $teamLabel, string $rowLabel, int $num): int
    {
        if (! ctype_digit($raw)) {
            throw ValidationException::forField(
                'field.net',
                'validation.net_entry',
                ['team' => $teamLabel, 'row' => $rowLabel, 'game' => $num, 'value' => $raw],
            );
        }

        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isBlind(array $input, string $page, int $playerId, int $num): bool
    {
        $value = $input['blind'][$page][$playerId] ?? null;

        return is_array($value) && ($value[$num] ?? '') === '1';
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException if the fixture is missing or incomplete.
     */
    private function fixture(array $input): array
    {
        $id = filter_var($input['fixture_id'] ?? null, FILTER_VALIDATE_INT);
        $fixture = is_int($id) && $id > 0 ? $this->data->teamFixture($id) : null;

        if ($fixture === null || $fixture['home'] === null || $fixture['away'] === null) {
            throw ValidationException::forField('field.fixture', 'results.fixture_not_found_text');
        }

        return $fixture;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function fixtureAndTeam(array $input): array
    {
        $fixture = $this->fixture($input);
        $page = $input['page'] ?? '';
        if (! in_array($page, self::PAGES, true)) {
            throw ValidationException::forField('common.team', 'validation.invalid_team');
        }

        return [$fixture, $fixture[$page]];
    }

    /**
     * Checks a file upload for PHP upload errors.
     *
     * @param array<string, mixed> $file
     */
    private function checkUpload(array $file): ?string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        return match ($error) {
            // tmp_name comes from PHP itself ($_FILES), not from the client.
            UPLOAD_ERR_OK => is_file((string) ($file['tmp_name'] ?? ''))
                ? null
                : $this->t->t('upload.unreadable'),
            UPLOAD_ERR_NO_FILE => $this->t->t('upload.no_file'),
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->t->t('upload.too_large'),
            default => $this->t->t('upload.failed', ['code' => $error]),
        };
    }

    /**
     * Runs an action and translates domain exceptions into an error message;
     * the data then stays unchanged.
     *
     * @param callable(): ActionResult $action
     */
    private function perform(callable $action): ActionResult
    {
        try {
            return $action();
        } catch (AccessDeniedException | ValidationException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (\RuntimeException $e) {
            // Domain exceptions (lineup, result entry, repository)
            // already carry messages phrased for the user.
            return ActionResult::error($this->t->error($e));
        }
    }
}
