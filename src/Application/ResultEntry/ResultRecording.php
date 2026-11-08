<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

use Bowlingliga\Application\RosterReader;
use Bowlingliga\Application\Transaction;
use Bowlingliga\Cache\CacheInvalidation;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\Domain\Handicap\HandicapChainPreview;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: saving entered game results as draft or confirmation,
 * including cache invalidation and flagging the played matchday (R22.2,
 * R22.6, R22.7, R24.1, R24.2, R24.4, R30.14–R30.18, R35.4).
 *
 * The use case connects the raw data persistence of the net results with the
 * status of the fixture, the cache invalidation and freezing the handicaps:
 *
 * - The score sheet of a real team lists the games of every roster player who
 *   played; players are substituted between games only, at most 4 play at the
 *   same time ({@see TeamSheetRules}, R21.2, R21.4). Players must belong to
 *   the team's roster (R21.5). A missing player is replaced by the roster
 *   player with the most games who does not play, then the next one; each
 *   serves once, in their own row with only blind games
 *   ({@see BlindPlayerOrder}, R27.4).
 * - {@see saveDraft()} persists the results entered so far as a draft and
 *   sets the completion status to "in Bearbeitung" (R30.15). The matchday is
 *   not flagged as played and the results do not appear in public views
 *   (R30.16).
 * - {@see confirmAndSave()} requires complete results of both teams
 *   (R30.4/R30.18) and complete earlier matchdays (R30.20), saves the net results transactionally as raw data,
 *   invalidates the dependent caches from the matchday onwards (R22.6,
 *   R35.4), freezes the handicaps of the matchday (R30.18) – without changing
 *   handicaps already frozen (R35.5) –, sets the completion status to
 *   "vollständig" and flags the matchday as played as soon as all fixtures of
 *   the matchday are complete (R24.1, R30.18).
 * - {@see removeResults()} removes the results of a fixture, resets the
 *   matchday to "not played", invalidates the dependent caches and falls back
 *   to the status "offen" or "in Bearbeitung" (R24.4, R30.15/16).
 * - {@see correctWithOptionalChainRecalculation()} saves corrected results
 *   and – if requested – returns a preview of the handicap chain
 *   recalculation (old/new diff) without persisting; applying only happens
 *   after explicit confirmation via {@see applyChainRecalculation()} (R12.18,
 *   R12.19).
 *
 * Every writing call is protected by {@see WriteAccess} (R5.1) and runs its
 * write steps in a common {@see Transaction}, so that an error in a later
 * step leaves no partial writes behind (R4.3).
 */
final class ResultRecording
{
    /**
     * Required number of game results for a complete score sheet: 2 teams ×
     * 3 games × 4 players on the lanes (R22.1, R30.4).
     */
    public const COMPLETE_COUNT = 24;

    private readonly TeamSheetRules $rules;
    private readonly BlindPlayerOrder $blindPlayers;

    public function __construct(
        private readonly ResultStore $results,
        private readonly FixtureStatusStore $status,
        private readonly MatchdayFlagStore $matchdayFlag,
        private readonly MatchdayContext $matchdayContext,
        private readonly HandicapFreezing $handicaps,
        private readonly CacheManager $cache,
        private readonly Validator $validator,
        private readonly WriteAccess $writeAccess,
        private readonly RosterReader $roster,
        PlayedGamesReader $playedGames,
        private readonly ?Transaction $transaction = null,
        private readonly ?ResultLog $log = null,
    ) {
        $this->rules           = new TeamSheetRules();
        $this->blindPlayers    = new BlindPlayerOrder($roster, $playedGames);
    }

    /**
     * Saves the results of a fixture entered so far as a draft and sets the
     * completion status to "in Bearbeitung" (R30.15).
     *
     * The results are neither flagged as played nor do they flow into
     * caches/public views (R30.16). A cache invalidation is only needed if the
     * matchday was played so far: if one of its fixtures falls back to
     * "in Bearbeitung", it no longer is (R24.4).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException for an invalid net
     *         value (R22.4/R22.5).
     * @throws ResultEntryException if a lineup rule is violated (R21.4, R21.5).
     */
    public function saveDraft(EnteredResults $results): void
    {
        $this->ensureWriteAccess();
        $this->check($results, false);

        $this->withinTransaction(function () use ($results): void {
            $wasPlayed = $this->status->areAllComplete($results->matchdayId);

            $this->saveLogged($results, 'draft_saved');
            $this->status->setCompletionStatus($results->fixtureId, 'in_progress');

            if ($wasPlayed) {
                $this->matchdayFlag->setIsPlayed($results->matchdayId, false);
                $this->invalidateCacheForMatchday($results->matchdayId);
            }
        });
    }

    /**
     * Confirms and saves complete results of a fixture (R22.7, R24.1, R30.18).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws ResultEntryException if the results are incomplete
     *         (R30.4/R30.18), a lineup rule is violated (R21.2, R21.4, R21.5,
     *         R27.2) or the matchday context is missing.
     * @throws \Bowlingliga\Security\ValidationException for an invalid net
     *         value (R22.4/R22.5).
     */
    public function confirmAndSave(EnteredResults $results): void
    {
        $this->ensureWriteAccess();
        $this->check($results, true);

        $this->withinTransaction(function () use ($results): void {
            // Handicaps are frozen from the averages of the earlier matchdays,
            // so those must be complete first; a draft stays possible (R30.20).
            $blocking = $this->status->blockingMatchdayNumber($results->fixtureId);
            if ($blocking !== null) {
                throw ResultEntryException::earlierMatchdayIncomplete($blocking);
            }

            // Save the net results as raw data (R22.6).
            $this->saveLogged($results, 'confirmed');

            // Invalidate the dependent caches from this matchday onwards (R22.6, R35.4). The
            // frozen handicaps are raw data and not affected (R35.5).
            $this->invalidateCacheForMatchday($results->matchdayId);

            // Freeze the handicaps of the matchday (R30.18); frozen values are
            // not changed retroactively (R12.12, R35.5).
            $this->handicaps->freezeHandicaps($results->matchdayId);

            // Status complete; the matchday only counts as played once all of its
            // fixtures are entered completely (R24.1, R24.2, R30.18).
            $this->status->setCompletionStatus($results->fixtureId, 'complete');
            $this->matchdayFlag->setIsPlayed(
                $results->matchdayId,
                $this->status->areAllComplete($results->matchdayId)
            );
        });
    }

    /**
     * Removes the entered results of a fixture and resets the matchday and the
     * completion status (R24.4, R30.15/16).
     *
     * The completion status falls back to "in Bearbeitung" if results remain
     * after removing, otherwise to "offen".
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws ResultEntryException if the matchday context is missing.
     */
    public function removeResults(int $fixtureId, int $matchdayId): void
    {
        $this->ensureWriteAccess();

        $this->withinTransaction(function () use ($fixtureId, $matchdayId): void {
            $before = $this->log !== null ? $this->results->findGames($fixtureId) : [];
            $this->results->removeResults($fixtureId);
            $this->logChanges($fixtureId, $before, [], 'removed');

            // The matchday is no longer completely played (R24.4).
            $this->matchdayFlag->setIsPlayed($matchdayId, false);
            $this->invalidateCacheForMatchday($matchdayId);

            $remainingStatus = $this->results->hasResults($fixtureId) ? 'in_progress' : 'open';
            $this->status->setCompletionStatus($fixtureId, $remainingStatus);
        });
    }

    /**
     * Saves corrected results of an already complete fixture and optionally
     * returns a preview of the handicap chain recalculation (R22.6, R12.17,
     * R12.18).
     *
     * The corrected net results are saved as raw data and the dependent caches
     * from the matchday onwards are invalidated. If
     * `$shouldRecalculateChain = true`, the chain is recalculated from the
     * matchday and the preview (old/new diff) is returned – **without**
     * changing the frozen handicaps (R12.19). Otherwise the handicap chain
     * stays unchanged and null is returned.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws ResultEntryException if the matchday context is missing.
     * @throws \Bowlingliga\Security\ValidationException for an invalid net
     *         value (R22.4/R22.5).
     */
    public function correctWithOptionalChainRecalculation(
        EnteredResults $results,
        bool $shouldRecalculateChain,
    ): ?HandicapChainPreview {
        $this->ensureWriteAccess();
        $this->check($results, false);

        $context = $this->withinTransaction(function () use ($results): array {
            $this->saveLogged($results, 'corrected');

            return $this->invalidateCacheForMatchday($results->matchdayId);
        });

        if (! $shouldRecalculateChain) {
            return null;
        }

        // Preview only (R12.18); persisting only happens via
        // applyChainRecalculation() after explicit confirmation (R12.19).
        return $this->handicaps->recalculateChain($context['season_id'], $context['matchday_number']);
    }

    /**
     * Applies a previously calculated handicap chain preview and persists the
     * recalculated handicaps as raw data – only after explicit confirmation
     * (R12.18, R12.19).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function applyChainRecalculation(HandicapChainPreview $preview): void
    {
        $this->ensureWriteAccess();
        $this->handicaps->applyChain($preview);
    }

    /**
     * Preview of the handicap chain recalculation from a corrected matchday,
     * without changing anything (R12.18, R12.19).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws ResultEntryException if the matchday context is missing.
     */
    public function previewHandicapChain(int $matchdayId): HandicapChainPreview
    {
        $this->ensureWriteAccess();
        $context = $this->matchdayContext->findSeasonAndCounter($matchdayId);
        if ($context === null) {
            throw ResultEntryException::matchdayContextMissing($matchdayId);
        }

        return $this->handicaps->recalculateChain($context['season_id'], $context['matchday_number']);
    }

    /**
     * Applies the recalculation from a matchday after explicit confirmation
     * (R12.18). The preview is recalculated and only applied if it matches the
     * confirmed one (fingerprint); persisting and cache invalidation run in
     * one transaction.
     *
     * @return HandicapChainPreview the applied preview
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws ResultEntryException if the chain has changed since the preview
     *         or the matchday context is missing.
     */
    public function applyHandicapChain(int $matchdayId, string $fingerprint): HandicapChainPreview
    {
        $preview = $this->previewHandicapChain($matchdayId);
        if (! hash_equals($preview->fingerprint(), $fingerprint)) {
            throw ResultEntryException::chainChanged();
        }

        $this->withinTransaction(function () use ($preview, $matchdayId): void {
            $this->handicaps->applyChain($preview);
            // Gross, points and tables from the matchday onwards depend on the
            // new handicaps (R35.4).
            $this->invalidateCacheForMatchday($matchdayId);
        });

        return $preview;
    }

    /**
     * Checks the entered results (R21.2, R21.4, R21.5, R22.4, R27.2, R27.4).
     *
     * Always: net range 0–300 (R22.4), roster membership (R21.5), at most 4
     * results per game and team (R21.4). For a complete score sheet in
     * addition 24 results, the rules of {@see TeamSheetRules::checkComplete()}
     * and the order of the blind players ({@see BlindPlayerOrder}). The order
     * is not checked again as long as the blind players stored for the
     * fixture stay the same, so that a later correction does not fail because
     * of games played on later matchdays (R35).
     *
     * @throws ResultEntryException
     * @throws \Bowlingliga\Security\ValidationException
     */
    private function check(EnteredResults $results, bool $complete): void
    {
        $this->checkNetRanges($results);

        $teams = array_filter(
            $results->gamesByTeam(),
            static fn (int $teamId): bool => ! in_array($teamId, $results->ghostTeamIds, true),
            ARRAY_FILTER_USE_KEY,
        );
        foreach ($teams as $teamId => $games) {
            $teamName = $results->teamNames[$teamId] ?? 'Team ' . $teamId;
            $this->checkRoster($teamId, $teamName, $games, $results->playerNames);
            $this->rules->checkEntered($games, $teamName);
        }

        if (! $complete) {
            return;
        }

        if ($results->quantity() < self::COMPLETE_COUNT) {
            throw ResultEntryException::incomplete();
        }
        foreach ($teams as $teamId => $games) {
            $teamName = $results->teamNames[$teamId] ?? 'Team ' . $teamId;
            $this->rules->checkComplete($games, $teamName, $results->playerNames);

            $participants = [];
            $blindPlayers = [];
            foreach ($this->rules->realGamesPerPlayer($games) as $playerId => $numbers) {
                if ($numbers === []) {
                    $blindPlayers[] = $playerId;
                } else {
                    $participants[] = $playerId;
                }
            }
            sort($blindPlayers);
            if ($blindPlayers !== $this->results->findBlindPlayers($results->fixtureId, $teamId)) {
                $this->blindPlayers->check($teamId, $results->matchdayId, $participants, $blindPlayers, $teamName, $results->playerNames);
            }
        }
    }
    /**
     * Every player of a team's score sheet must belong to the roster (R21.5).
     *
     * @param list<EnteredGame>  $games
     * @param array<int, string> $playerNames
     *
     * @throws ResultEntryException
     */
    private function checkRoster(int $teamId, string $teamName, array $games, array $playerNames): void
    {
        $roster = array_fill_keys($this->roster->findPlayerIdsForTeamSeason($teamId), true);
        foreach ($games as $game) {
            if ($game->isPlayerRow() && ($game->playerId === null || ! isset($roster[$game->playerId]))) {
                throw ResultEntryException::notInRoster($playerNames[$game->playerId ?? 0] ?? 'Spieler ' . ($game->playerId ?? '?'), $teamName);
            }
        }
    }

    /**
     * Checks all entered net values for the allowed range 0–300
     * (R22.4/R22.5); for an invalid value nothing is saved.
     *
     * @throws \Bowlingliga\Security\ValidationException
     */
    private function checkNetRanges(EnteredResults $results): void
    {
        foreach ($results->games as $game) {
            $this->validator->checkNet($game->net);
        }
    }

    /**
     * Invalidates the dependent caches from the matchday onwards and returns
     * the matchday context (season, counter) (R35.4).
     *
     * @return array{season_id: int, matchday_number: int}
     *
     * @throws ResultEntryException if the matchday context is missing.
     */
    private function invalidateCacheForMatchday(int $matchdayId): array
    {
        $context = $this->matchdayContext->findSeasonAndCounter($matchdayId);
        if ($context === null) {
            throw ResultEntryException::matchdayContextMissing($matchdayId);
        }

        $this->cache->invalidate(
            CacheInvalidation::fromMatchday($context['season_id'], $context['matchday_number'])
        );

        return $context;
    }

    /**
     * Runs the write steps in the common transaction; without a transaction
     * port (e.g. in unit tests) directly.
     *
     * @template T
     *
     * @param callable(): T $steps
     *
     * @return T
     */
    /**
     * Saves the results and records every changed value in the history of the
     * fixture (value before → after), if a log is wired (admin; the teams log
     * their entries themselves).
     */
    private function saveLogged(EnteredResults $results, string $action): void
    {
        $before = $this->log !== null ? $this->results->findGames($results->fixtureId) : [];
        $this->results->saveResults($results);
        $this->logChanges(
            $results->fixtureId,
            $before,
            array_values(array_filter($results->games, static fn (EnteredGame $game): bool => $game->isPlayerRow())),
            $action,
        );
    }

    /**
     * @param list<EnteredGame> $before
     * @param list<EnteredGame> $after
     */
    private function logChanges(int $fixtureId, array $before, array $after, string $action): void
    {
        if ($this->log === null) {
            return;
        }

        $values = static function (array $games): array {
            $byKey = [];
            foreach ($games as $game) {
                $byKey[$game->playerId . '-' . $game->gameNumber] = [$game, $game->net . ($game->isBlind ? ' blind' : '')];
            }

            return $byKey;
        };
        $old = $values($before);
        $new = $values($after);

        foreach (array_keys($old + $new) as $key) {
            $previous = $old[$key][1] ?? null;
            $next     = $new[$key][1] ?? null;
            if ($previous !== $next) {
                $game = ($new[$key] ?? $old[$key])[0];
                $this->log->changed($fixtureId, (int) $game->playerId, $game->gameNumber, $previous, $next);
            }
        }
        $this->log->noted($fixtureId, $action);
    }

    private function withinTransaction(callable $steps): mixed
    {
        return $this->transaction !== null ? $this->transaction->perform($steps) : $steps();
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
