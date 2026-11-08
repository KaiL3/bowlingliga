<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamEntry;

use Bowlingliga\Application\ResultEntry\BlindFilling;
use Bowlingliga\Application\ResultEntry\BlindPlayerOrder;
use Bowlingliga\Application\ResultEntry\BlindPosition;
use Bowlingliga\Application\ResultEntry\EnteredGame;
use Bowlingliga\Application\ResultEntry\EnteredResults;
use Bowlingliga\Application\ResultEntry\FixtureLogEntry;
use Bowlingliga\Application\ResultEntry\ResultEntryException;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Application\ResultEntry\TeamSheetRules;
use Bowlingliga\Application\Transaction;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\TeamEntryCodes;
use Bowlingliga\Security\WriteAccess;

/**
 * Result entry by the teams with confirmation by the opponent (R37,
 * decision of 2026-10-25).
 *
 * Each team opens the page of its fixture with the code from the printed
 * score sheet and enters game by game its own lineup and its own net results:
 * who plays, who drops out without a substitute ("blind"). Free positions are
 * filled with derived blind values ({@see BlindFilling}). Moving on to the
 * next game checks the game ({@see checkGame()}). At the end the team sends
 * its entry; the opponent then confirms it or sends it back with a reason.
 * Each team may confirm the opponent's entry as soon as it is sent, also
 * before sending its own. Once both entries are sent and confirmed, the
 * results are saved through {@see ResultRecording::confirmAndSave()} exactly
 * like a confirmation by the admin: handicaps frozen, fixture complete,
 * matchday played once all its fixtures are complete (R24.1). Until then
 * nothing is public and nothing counts.
 *
 * Against the ghost team only the real team gets a code; the ghost team's
 * results are fixed, and sending the entry is its confirmation (R37.10).
 *
 * A code is valid only for its fixture and side, on the matchday until noon of
 * the next day, while the season has the team entry switched on. The admin can
 * take over the entry into the virtual score sheet at any time (dispute,
 * R37.8) and renew the codes.
 */
final class TeamResultEntry
{
    public const GAMES = [1, 2, 3];
    private const PLAYERS_PER_GAME = 4;
    private const VALID_UNTIL_HOUR_NEXT_DAY = 12;
    private const NOTE_MAX = 500;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    private readonly TeamSheetRules $rules;

    public function __construct(
        private readonly TeamEntryStore $store,
        private readonly TeamEntryCodes $codes,
        private readonly ResultRecording $teamRecording,
        private readonly TeamEntryWriteAccess $teamAccess,
        private readonly ResultRecording $adminRecording,
        private readonly WriteAccess $adminAccess,
        private readonly BlindPlayerOrder $blindPlayers,
        private readonly BlindFilling $filling,
        private readonly Transaction $transaction,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->rules = new TeamSheetRules();
    }

    // --- Teams (with code) ------------------------------------------------------

    /** @throws TeamEntryException */
    public function open(string $code): TeamEntrySheet
    {
        [$fixtureId, $side, $fixture] = $this->resolve($code);

        return $this->sheet($code, $fixtureId, $side, $fixture);
    }

    /**
     * Saves the own lineup and nets of one game (R37.3, R37.4). A player
     * without a mark does not play; a net is only kept for "plays".
     *
     * @param array<int|string, mixed> $lineup [player id] => '' | 'plays' | 'blind'
     * @param array<int|string, mixed> $nets   [player id] => '' | net
     *
     * @throws TeamEntryException
     */
    public function saveGame(string $code, int $game, array $lineup, array $nets): void
    {
        if (! in_array($game, self::GAMES, true)) {
            throw TeamEntryException::unknownGame();
        }
        [$fixtureId, $side] = $this->resolve($code);

        $this->transaction->perform(function () use ($code, $fixtureId, $side, $game, $lineup, $nets): void {
            $this->store->lock($fixtureId);
            $sheet = $this->freshSheet($code, $fixtureId, $side);
            if (! $sheet->canEdit()) {
                throw TeamEntryException::alreadySubmitted();
            }

            $team = $sheet->ownTeam();
            foreach (array_keys($lineup + $nets) as $playerId) {
                if (! isset($team['players'][(int) $playerId])) {
                    throw TeamEntryException::unknownPlayer();
                }
            }

            // Wanted state of the game: player id => [is blind, net].
            $wanted = [];
            foreach ($team['players'] as $playerId => $name) {
                $mark = is_string($lineup[$playerId] ?? null) ? $lineup[$playerId] : '';
                $raw  = is_string($nets[$playerId] ?? null) ? trim($nets[$playerId]) : '';
                if ($mark === 'blind') {
                    $wanted[$playerId] = [true, null];
                } elseif ($mark === 'plays') {
                    if ($raw !== '' && (! ctype_digit($raw) || (int) $raw > 300)) {
                        throw TeamEntryException::invalidNet($name, $game);
                    }
                    $wanted[$playerId] = [false, $raw === '' ? null : (int) $raw];
                }
            }
            $playing = count(array_filter($wanted, static fn (array $w): bool => ! $w[0]));
            if ($playing > self::PLAYERS_PER_GAME) {
                throw TeamEntryException::tooManyPlayers($game);
            }

            $current = $sheet->participation($team['team_season_id']);
            $mark = static fn (?bool $isBlind): ?string => $isBlind === null ? null : ($isBlind ? 'blind' : 'spielt');
            foreach (array_keys($team['players']) as $playerId) {
                $before = $current[$playerId][$game] ?? null;
                $after  = $wanted[$playerId] ?? null;
                if ($before !== null && $after !== null && $before['is_blind'] === $after[0] && $before['net'] === $after[1]) {
                    continue;
                }

                $beforeBlind = $before === null ? null : $before['is_blind'];
                $afterBlind  = $after === null ? null : $after[0];
                if ($beforeBlind !== $afterBlind) {
                    $this->store->setParticipation($fixtureId, $team['team_season_id'], $playerId, $game, $afterBlind);
                    $this->store->logChange($fixtureId, $side, 'lineup', $playerId, $game, $mark($beforeBlind), $mark($afterBlind));
                }

                $beforeNet = $before['net'] ?? null;
                $afterNet  = $after[1] ?? null;
                if ($after !== null && $beforeNet !== $afterNet) {
                    $this->store->setNet($fixtureId, $playerId, $game, $afterNet);
                }
                if ($beforeNet !== $afterNet) {
                    $this->store->logChange(
                        $fixtureId,
                        $side,
                        'net',
                        $playerId,
                        $game,
                        $beforeNet !== null ? (string) $beforeNet : null,
                        $afterNet !== null ? (string) $afterNet : null,
                    );
                }
            }
        });
    }

    /**
     * Checks one game of the own entry before moving on: at least one real
     * player, at most 4, every result entered, no re-entry, "blind" only for a
     * player who played before, every free position filled (R27.1–R27.5).
     *
     * @throws TeamEntryException
     */
    public function checkGame(string $code, int $game): void
    {
        if (! in_array($game, self::GAMES, true)) {
            throw TeamEntryException::unknownGame();
        }

        $this->checkRound($this->open($code), $game);
    }

    /**
     * Sends the own entry (R37.5): all three games are checked. Against the
     * ghost team sending is the confirmation: the results are final at once
     * (R37.10).
     *
     * @return bool true if the results are final now
     *
     * @throws TeamEntryException|\RuntimeException if the entry is incomplete
     */
    public function submit(string $code): bool
    {
        [$fixtureId, $side] = $this->resolve($code);

        return $this->transaction->perform(function () use ($code, $fixtureId, $side): bool {
            $this->store->lock($fixtureId);
            $sheet = $this->freshSheet($code, $fixtureId, $side);
            if (! $sheet->canSubmit()) {
                throw TeamEntryException::alreadySubmitted();
            }

            $this->checkComplete($sheet);
            $this->store->setSubmitted($fixtureId, $side, true);
            // A previous rejection by the opponent is answered by this new entry.
            $this->store->setRejection($fixtureId, TeamEntrySide::opposite($side), null);
            $this->store->log($fixtureId, $side, 'submitted');

            if ($sheet->ghostOpponent()) {
                $this->store->setConfirmed($fixtureId, $side);
                $this->saveFinally($sheet, FixtureLogEntry::FINAL_GHOST_TEAM);

                return true;
            }

            return false;
        });
    }

    /** Withdraws the sent own entry as long as the opponent has not confirmed it. */
    public function withdraw(string $code): void
    {
        [$fixtureId, $side] = $this->resolve($code);

        $this->transaction->perform(function () use ($code, $fixtureId, $side): void {
            $this->store->lock($fixtureId);
            $sheet = $this->freshSheet($code, $fixtureId, $side);
            if (! $sheet->canWithdraw()) {
                throw match (true) {
                    ! $sheet->own->submitted => TeamEntryException::notSent(),
                    $sheet->opponent->confirmed => TeamEntryException::alreadyConfirmed($sheet->opponentTeam()['team_name']),
                    default => TeamEntryException::finished(),
                };
            }

            $this->store->setSubmitted($fixtureId, $side, false);
            $this->store->log($fixtureId, $side, 'withdrawn');
        });
    }

    /**
     * Confirms the opponent's sent entry (R37.6). If the opponent has
     * confirmed the own entry as well, the results are saved finally.
     *
     * @return bool true if the results are final now
     *
     * @throws TeamEntryException|\RuntimeException if the final saving fails;
     *         nothing is confirmed then
     */
    public function confirm(string $code): bool
    {
        [$fixtureId, $side] = $this->resolve($code);

        return $this->transaction->perform(function () use ($code, $fixtureId, $side): bool {
            $this->store->lock($fixtureId);
            $sheet = $this->freshSheet($code, $fixtureId, $side);
            $this->ensureCanConfirm($sheet);

            $this->store->setConfirmed($fixtureId, $side);
            $this->store->log($fixtureId, $side, 'confirmed');

            // The opponent can only have confirmed a sent own entry.
            if (! $sheet->opponent->confirmed) {
                return false;
            }

            $this->saveFinally($sheet, FixtureLogEntry::FINAL_BOTH_CONFIRMED);

            return true;
        });
    }

    /**
     * Sends the opponent's entry back with a reason (R37.7); the opponent can
     * correct it, otherwise the admin settles the dispute on site.
     */
    public function reject(string $code, string $note): void
    {
        [$fixtureId, $side] = $this->resolve($code);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > self::NOTE_MAX) {
            throw TeamEntryException::noteRequired();
        }

        $this->transaction->perform(function () use ($code, $fixtureId, $side, $note): void {
            $this->store->lock($fixtureId);
            $sheet = $this->freshSheet($code, $fixtureId, $side);
            $this->ensureCanConfirm($sheet);

            $this->store->setSubmitted($fixtureId, TeamEntrySide::opposite($side), false);
            $this->store->setRejection($fixtureId, $side, $note);
            $this->store->log($fixtureId, $side, 'rejected', $note);
        });
    }

    /**
     * Positions of a team filled with blind values, for the page; optionally
     * for entries not saved yet (lineup taken over from the game before).
     *
     * @param array{team_season_id: int, players: array<int, string>}           $team
     * @param array<int, array<int, array{is_blind: bool, net: int|null}>>|null $entries
     *
     * @return list<BlindPosition>
     */
    public function blindPositions(TeamEntrySheet $sheet, array $team, ?array $entries = null): array
    {
        return $this->filling->fill(
            $team['team_season_id'],
            $sheet->fixture['matchday_id'],
            $entries ?? $sheet->participation($team['team_season_id']),
            (int) $sheet->fixture['ghost_score'],
        );
    }

    // --- Admin ------------------------------------------------------------------

    public function isEnabled(int $seasonId): bool
    {
        return $this->store->isEnabled($seasonId);
    }

    public function setEnabled(int $seasonId, bool $enabled): void
    {
        $this->ensureAdmin();
        $this->store->setEnabled($seasonId, $enabled);
    }

    /**
     * Code of a side for the printed score sheet and the admin page, or null if
     * the fixture is not open to team entry.
     */
    public function codeFor(int $fixtureId, string $side): ?string
    {
        $fixture = $this->store->fixture($fixtureId);
        if ($fixture === null || ! $this->isAvailable($fixture) || $fixture[$side]['is_ghost_team']) {
            return null;
        }

        return $this->codes->create($fixtureId, $side, $this->store->sides($fixtureId)[$side]->codeVersion);
    }

    /**
     * State of the team entry of a fixture for the admin, or null if it is not
     * open to team entry.
     *
     * @return array{sides: array{home: TeamEntrySide, away: TeamEntrySide}, games: list<array<string, mixed>>, fixture: array<string, mixed>}|null
     */
    public function adminState(int $fixtureId): ?array
    {
        $fixture = $this->store->fixture($fixtureId);
        if ($fixture === null || ! $this->isAvailable($fixture)) {
            return null;
        }

        return ['sides' => $this->store->sides($fixtureId), 'games' => $this->store->games($fixtureId), 'fixture' => $fixture];
    }

    /** @return list<array{fixture_id: int, matchday_number: int, date: string, home: string, away: string}> */
    public function activeFixtures(int $seasonId): array
    {
        return $this->store->activeFixtures($seasonId);
    }

    /**
     * Takes the values entered by the teams over into the virtual score sheet
     * as a draft, including the derived blind values, and ends the team entry
     * (dispute, R37.8).
     *
     * @return int number of results taken over
     */
    public function takeOver(int $fixtureId): int
    {
        $this->ensureAdmin();

        return $this->transaction->perform(function () use ($fixtureId): int {
            $this->store->lock($fixtureId);
            $fixture = $this->store->fixture($fixtureId) ?? throw TeamEntryException::notAvailable();
            $sheet = $this->sheet('', $fixtureId, 'home', $fixture);

            $games = [];
            $names = [];
            $teams = [];
            foreach (['home', 'away'] as $side) {
                $team = $fixture[$side] ?? null;
                if ($team === null || $team['is_ghost_team']) {
                    continue;
                }
                $teams[$team['team_season_id']] = $team['team_name'];
                $names += $team['players'];
                array_push($games, ...$this->teamGames($sheet, $team));
            }

            if ($games !== []) {
                $this->adminRecording->saveDraft(new EnteredResults($fixtureId, $fixture['matchday_id'], $games, [], $teams, $names));
            }
            $this->store->close($fixtureId);
            $this->store->log($fixtureId, null, 'taken_over', (string) count($games));

            return count($games);
        });
    }

    public function renewCodes(int $fixtureId): void
    {
        $this->ensureAdmin();
        $this->store->renewCodes($fixtureId);
        $this->store->log($fixtureId, null, 'codes_renewed');
    }

    // --- Internals --------------------------------------------------------------

    /**
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     *
     * @throws TeamEntryException
     */
    private function resolve(string $code): array
    {
        $data = $this->codes->read(trim($code));
        $fixture = $data !== null ? $this->store->fixture($data['fixture_id']) : null;
        if ($data === null || $fixture === null) {
            throw TeamEntryException::invalidCode();
        }
        if ($this->store->sides($data['fixture_id'])[$data['side']]->codeVersion !== $data['version']) {
            throw TeamEntryException::invalidCode();
        }
        if (! $this->isAvailable($fixture)) {
            throw TeamEntryException::notAvailable();
        }
        if ($fixture[$data['side']]['is_ghost_team']) {
            throw TeamEntryException::invalidCode();
        }

        $start = new \DateTimeImmutable($fixture['date'] . ' 00:00:00');
        $end   = $start->modify('+1 day')->setTime(self::VALID_UNTIL_HOUR_NEXT_DAY, 0);
        $now   = ($this->clock)();
        if ($now < $start || $now >= $end) {
            throw TeamEntryException::notToday($start->format('Y-m-d'));
        }

        return [$data['fixture_id'], $data['side'], $fixture];
    }

    /** @param array<string, mixed> $fixture */
    private function isAvailable(array $fixture): bool
    {
        // A cancelled matchday takes no results (R8.20).
        return $fixture['home'] !== null && $fixture['away'] !== null
            && ! ($fixture['is_cancelled'] ?? false)
            && ! ($fixture['home']['is_ghost_team'] && $fixture['away']['is_ghost_team'])
            && $this->store->isEnabled($fixture['season_id']);
    }

    /** @param array<string, mixed> $fixture */
    private function sheet(string $code, int $fixtureId, string $side, array $fixture): TeamEntrySheet
    {
        $sides = $this->store->sides($fixtureId);
        $finished = $fixture['status'] === 'complete' || $sides['home']->closed || $sides['away']->closed;

        return new TeamEntrySheet(
            $code,
            $side,
            $fixture,
            $sides[$side],
            $sides[TeamEntrySide::opposite($side)],
            $this->store->games($fixtureId),
            $finished,
        );
    }

    private function freshSheet(string $code, int $fixtureId, string $side): TeamEntrySheet
    {
        $fixture = $this->store->fixture($fixtureId) ?? throw TeamEntryException::invalidCode();
        $sheet = $this->sheet($code, $fixtureId, $side, $fixture);
        if ($sheet->finished) {
            throw TeamEntryException::finished();
        }

        return $sheet;
    }

    private function ensureCanConfirm(TeamEntrySheet $sheet): void
    {
        if (! $sheet->canConfirm()) {
            throw $sheet->own->confirmed
                ? TeamEntryException::alreadyConfirmed($sheet->ownTeam()['team_name'])
                : TeamEntryException::notSubmitted($sheet->opponentTeam()['team_name']);
        }
    }

    /**
     * One game of the own entry: at least one real player, at most 4, every
     * result entered, a player's own games without gap and blind only after
     * having played, every free position filled.
     *
     * @throws TeamEntryException
     */
    private function checkRound(TeamEntrySheet $sheet, int $game): void
    {
        $team = $sheet->ownTeam();
        $entries = $sheet->participation($team['team_season_id']);

        $playing = 0;
        foreach ($entries as $playerId => $games) {
            $entry = $games[$game] ?? null;
            if ($entry === null) {
                continue;
            }
            $name = $team['players'][$playerId] ?? 'Spieler ' . $playerId;

            if ($entry['is_blind']) {
                // Drops out without a substitute: only after having played the game before.
                if (! isset($games[$game - 1]) || ! $this->hasRealGameBefore($games, $game)) {
                    throw TeamEntryException::blindWithoutGame($name, $game);
                }
                continue;
            }

            $playing++;
            if ($entry['net'] === null) {
                throw TeamEntryException::missingNet($name, $game);
            }
            // A player who left (substituted or dropped out) does not come back.
            foreach ($games as $earlier => $other) {
                if ($earlier < $game && ($other['is_blind'] || ($earlier < $game - 1 && ! isset($games[$game - 1])))) {
                    throw TeamEntryException::reentry($name, $game);
                }
            }
        }

        if ($playing === 0) {
            throw TeamEntryException::gameEmpty($game);
        }
        if ($playing > self::PLAYERS_PER_GAME) {
            throw TeamEntryException::tooManyPlayers($game);
        }

        $blind = count(array_filter($entries, static fn (array $games): bool => ($games[$game]['is_blind'] ?? false)));
        $filled = array_filter(
            $this->blindPositions($sheet, $team),
            static fn (BlindPosition $p): bool => $p->gameNumber === $game && $p->kind !== BlindPosition::OWN,
        );
        foreach ($filled as $position) {
            if ($position->net === null) {
                throw TeamEntryException::blindValueMissing($game);
            }
        }
        if ($playing + $blind + count($filled) < self::PLAYERS_PER_GAME) {
            throw TeamEntryException::blindValueMissing($game);
        }
    }

    /** @param array<int, array{is_blind: bool, net: int|null}> $games */
    private function hasRealGameBefore(array $games, int $game): bool
    {
        foreach ($games as $number => $entry) {
            if ($number < $game && ! $entry['is_blind']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole own entry before sending: every game, then the rules of a
     * complete score sheet like the admin confirmation (R37.5).
     */
    private function checkComplete(TeamEntrySheet $sheet): void
    {
        foreach (self::GAMES as $game) {
            $this->checkRound($sheet, $game);
        }

        $team = $sheet->ownTeam();
        $games = $this->teamGames($sheet, $team);
        $this->rules->checkComplete($games, $team['team_name'], $team['players']);

        $participants = [];
        $blindPlayers = [];
        foreach ($this->rules->realGamesPerPlayer($games) as $playerId => $numbers) {
            if ($numbers === []) {
                $blindPlayers[] = $playerId;
            } else {
                $participants[] = $playerId;
            }
        }
        $this->blindPlayers->check($team['team_season_id'], $sheet->fixture['matchday_id'], $participants, $blindPlayers, $team['team_name'], $team['players']);
    }

    /**
     * Games of a real team: its entered real games and the positions filled
     * with blind values.
     *
     * @param array{team_season_id: int, players: array<int, string>} $team
     *
     * @return list<EnteredGame>
     */
    private function teamGames(TeamEntrySheet $sheet, array $team): array
    {
        $games = [];
        foreach ($sheet->participation($team['team_season_id']) as $playerId => $entries) {
            foreach ($entries as $game => $entry) {
                if (! $entry['is_blind'] && $entry['net'] !== null) {
                    $games[] = EnteredGame::player($team['team_season_id'], $playerId, $game, $entry['net']);
                }
            }
        }
        foreach ($this->blindPositions($sheet, $team) as $position) {
            $game = $position->toEnteredGame($team['team_season_id']);
            if ($game !== null) {
                $games[] = $game;
            }
        }

        return $games;
    }

    private function results(TeamEntrySheet $sheet): EnteredResults
    {
        $games = [];
        $teams = [];
        $names = [];
        $ghosts = [];
        foreach (['home', 'away'] as $side) {
            $team = $sheet->fixture[$side];
            $teams[$team['team_season_id']] = $team['team_name'];
            $names += $team['players'];
            if ($team['is_ghost_team']) {
                // Fixed lineup and net = ghost score, as in the virtual score sheet (R20).
                $ghosts[] = $team['team_season_id'];
                for ($slot = 1; $slot <= self::PLAYERS_PER_GAME; $slot++) {
                    foreach (self::GAMES as $game) {
                        $games[] = EnteredGame::ghost($team['team_season_id'], $slot, $game, $sheet->fixture['ghost_score']);
                    }
                }
                continue;
            }
            array_push($games, ...$this->teamGames($sheet, $team));
        }

        return new EnteredResults($sheet->fixture['id'], $sheet->fixture['matchday_id'], $games, $ghosts, $teams, $names);
    }

    /** Saves the results like a confirmation by the admin and ends the team entry (R37.6, R37.10). */
    private function saveFinally(TeamEntrySheet $sheet, string $detail): void
    {
        $results = $this->results($sheet);
        try {
            $this->teamAccess->grantDuring(fn () => $this->teamRecording->confirmAndSave($results));
        } catch (ResultEntryException $e) {
            // The entries stay sent; the league management confirms the
            // fixture once the earlier matchdays are complete (R30.20).
            throw $e->isEarlierMatchdayIncomplete() ? TeamEntryException::earlierMatchdayIncomplete() : $e;
        }
        $this->store->close($sheet->fixture['id']);
        $this->store->log($sheet->fixture['id'], null, 'final', $detail);
    }

    private function ensureAdmin(): void
    {
        if (! $this->adminAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
