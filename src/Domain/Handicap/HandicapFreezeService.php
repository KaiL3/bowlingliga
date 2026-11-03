<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Service for freezing the handicap as raw data (R12.9, R12.12, R35.5).
 *
 * This service connects the pure, side-effect-free handicap calculation
 * ({@see HandicapModule}) with raw data persistence via the
 * {@see FrozenHandicapStore} port (implemented by
 * {@see \Bowlingliga\Persistence\FrozenHandicapRepository}). The
 * calculation itself deliberately stays in the {@see HandicapModule}; this
 * class only orchestrates the calculation and persistence flow.
 *
 * Domain flow when freezing (R12.12):
 * - The handicap state assigned to the matchday is preserved so that the
 *   gross calculation of this matchday stays unchanged even if the player's
 *   average changes later.
 * - The relevant average state depends on the first matchday special case
 *   (R12.9): for a player's very first matchday – for which neither a played
 *   game nor a previous season record existed before – the average state
 *   **at the end** of the matchday (including the games played on that
 *   matchday) applies. In all other cases (R12.8) the average state
 *   **immediately before** the matchday applies.
 *
 * Choosing the correct average state is up to the calling use case (result
 * entry/recalculation), since only there the temporal context of the matchday
 * and the player's raw data are available. The relevant average is passed to
 * this service as a value; the {@see $isFirstMatchday} flag passes the domain
 * context on to the calculation.
 *
 * The frozen handicap is **raw data** and never a cache (R35.5): it is only
 * written through this freezing and is not touched by the cache invalidation
 * mechanism.
 *
 * @see R12.9, R12.12, R35.5
 */
final class HandicapFreezeService
{
    public function __construct(
        private readonly HandicapModule $handicapModule,
        private readonly FrozenHandicapStore $frozenHandicaps,
    ) {
    }

    /**
     * Freezes a player's handicap for a matchday as raw data (R12.9, R12.12,
     * R35.5).
     *
     * The relevant average state is determined by the caller and passed as a
     * value:
     * - Regular case (R12.8): state immediately before the matchday.
     * - First matchday special case (R12.9, `$isFirstMatchday = true`): state
     *   at the end of the matchday including the games played on that
     *   matchday.
     *
     * The handicap is determined by the pure {@see HandicapModule}. Only a
     * regularly calculated handicap ({@see HandicapResult::hasHandicap()}) is
     * persisted as raw data via the {@see FrozenHandicapStore} (upsert per
     * matchday/player). If the calculation yields no handicap (missing formula,
     * no average, invalid formula result – R12.13/R12.14/R12.16), nothing is
     * frozen and the result is returned unchanged to the caller so that it can
     * handle the case.
     *
     * @param int                  $playerId        Player id.
     * @param int                  $matchdayId      Id of the matchday the
     *                                              handicap is frozen for.
     * @param float|null           $average         Relevant net average (state
     *                                              according to R12.8/R12.9) or
     *                                              null if there is none.
     * @param int                  $targetScore     Globally defined target score
     *                                              (R12.2).
     * @param HandicapFormula|null $formula         Formula configured per season
     *                                              or null (R12.13).
     * @param int                  $maxHandicap     Maximum handicap of the
     *                                              season (R12.3/R12.10).
     * @param bool                 $isFirstMatchday Marks the first matchday
     *                                              special case (R12.9).
     *
     * @return HandicapResult The calculation result; on success it has also
     *                        been frozen as raw data.
     */
    public function freezeHandicap(
        int $playerId,
        int $matchdayId,
        ?float $average,
        int $targetScore,
        ?HandicapFormula $formula,
        int $maxHandicap,
        bool $isFirstMatchday = false,
    ): HandicapResult {
        $result = $this->handicapModule->calculateHandicap(
            $average,
            $targetScore,
            $formula,
            $maxHandicap,
            $isFirstMatchday,
        );

        // Only a regularly calculated handicap is frozen as raw data
        // (R12.12). Error and special cases (R12.13/R12.14/R12.16) carry no
        // handicap and are passed back to the caller unchanged.
        if ($result->hasHandicap()) {
            /** @var int $handicap With hasHandicap() always an integer value. */
            $handicap = $result->handicap();
            $this->frozenHandicaps->save($matchdayId, $playerId, $handicap);
        }

        return $result;
    }

    /**
     * Applies a previously calculated handicap chain preview and persists the
     * recalculated handicaps as raw data – **only after explicit confirmation**
     * by the administrator (R12.18, R12.19).
     *
     * This method is the persistence step of the chain recalculation: the pure
     * calculation happens beforehand via
     * {@see HandicapChainRecalculation::recalculateHandicapChain()}, which
     * returns a side-effect-free preview. Without calling this apply the
     * previously frozen handicaps stay unchanged (R12.19); merely creating a
     * preview never changes raw data.
     *
     * Only entries that carry a new, regularly calculated handicap value
     * compared to the previous state are written
     * ({@see HandicapChainChange::isChange()} and a non-null `newHandicap`).
     * Entries without a handicap (missing formula, no average, invalid formula
     * result – R12.13/R12.14/R12.16) leave the existing frozen value untouched,
     * since the frozen handicap is raw data written only through freezing
     * (R35.5).
     *
     * @param HandicapChainPreview $preview The preview to apply.
     *
     * @return list<HandicapChainChange> The changes actually persisted (subset
     *                                   of the preview).
     */
    public function applyHandicapChainPreview(HandicapChainPreview $preview): array
    {
        $applied = [];

        foreach ($preview->all() as $change) {
            // Only real changes with a recalculated handicap are persisted as
            // raw data (R12.18). Unchanged entries and those
            // without a handicap stay untouched (R12.19/R35.5).
            if (!$change->isChange() || $change->newHandicap === null) {
                continue;
            }

            $this->frozenHandicaps->save(
                $change->matchdayId,
                $change->playerId,
                $change->newHandicap,
            );

            $applied[] = $change;
        }

        return $applied;
    }
}
