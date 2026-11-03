<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * Blind player service (R27, R21.3, R21.6, R3.7–11, R22.2, delimitation from
 * R20.x).
 *
 * Pure, side-effect-free calculation service. It determines the roster player
 * to substitute for a game marked blind, derives the blind reference values
 * (net/gross), checks deviations against the sheet value and fills incomplete
 * team lineups up to exactly 4 positions.
 *
 * Basic rules:
 * - The stored net value is always the sheet value (R27.10, R3.10, R22.2);
 *   derived blind values only serve as a plausibility reference.
 * - Blind values count towards team pins/points (R27.12), never towards
 *   average, handicap or honours (R27.13) – the exclusion logic itself lives
 *   in the respective modules; this service only supplies reference and
 *   filling values.
 * - No persistence, no dependency on time or randomness: equal inputs always
 *   give equal results.
 */
final class BlindPlayerService
{
    /** Fixed deduction for deriving the blind reference value (R27.6–7). */
    private const BLIND_DEDUCTION = 10;

    /** Lower clamp bound for net/gross (R27.8). */
    private const MIN_VALUE = 0;

    /** Upper clamp bound for net/gross (R27.9). */
    private const MAX_VALUE = 300;

    /** Exactly 4 positions per lineup (R27.1). */
    private const MAX_POSITIONS = 4;

    /**
     * Determines the roster player to substitute for the blind position
     * (R27.4): the one with the most games played among the roster players not
     * lined up regularly on this evening.
     *
     * On a tie in games played, the player with the smallest player id is
     * chosen deterministically so that the result is reproducible. If no
     * roster player is left that is not lined up regularly, the method returns
     * null (no blind player can be determined).
     *
     * @param list<RosterPlayer> $roster Roster players of the team including
     *        games played and lineup status (R27.4)
     */
    public function determineSubstitutePlayer(array $roster): ?RosterPlayer
    {
        $candidates = array_values(array_filter(
            $roster,
            static fn (RosterPlayer $s): bool => ! $s->regularlyLinedUp
        ));

        if ($candidates === []) {
            return null;
        }

        $substitute = $candidates[0];
        foreach ($candidates as $player) {
            if ($player->gamesPlayed > $substitute->gamesPlayed) {
                $substitute = $player;
                continue;
            }

            if (
                $player->gamesPlayed === $substitute->gamesPlayed
                && $player->playerId < $substitute->playerId
            ) {
                $substitute = $player;
            }
        }

        return $substitute;
    }

    /**
     * Checks the eligibility of a player already listed as blind player
     * (R27.14–15): if another roster player not lined up regularly has more
     * games played, the operation is not rejected but a warning is signalled
     * (return true). The decision stays with the administrator.
     *
     * @param list<RosterPlayer> $roster
     * @return bool true if an eligibility warning is to be shown
     */
    public function checkPermission(int $listedPlayerId, array $roster): bool
    {
        $substitute = $this->determineSubstitutePlayer($roster);

        if ($substitute === null) {
            return false;
        }

        return $substitute->playerId !== $listedPlayerId
            && $substitute->gamesPlayed > $this->gamesPlayedBy($listedPlayerId, $roster);
    }

    /**
     * Derived blind net as plausibility reference (R27.6, R27.8–9):
     * clamp(net average − 10, 0, 300).
     */
    public function derivedBlindNet(float $netAverage): int
    {
        return $this->clamp((int) round($netAverage - self::BLIND_DEDUCTION));
    }

    /**
     * Derived blind gross as plausibility reference (R27.7, R27.8–9):
     * clamp(net average − 10 + handicap, 0, 300).
     */
    public function derivedBlindGross(float $netAverage, int $handicap): int
    {
        return $this->clamp((int) round($netAverage - self::BLIND_DEDUCTION + $handicap));
    }

    /**
     * Checks the deviation of a game marked blind (R27.11).
     *
     * Without a reference value (first evening special case, R27.16 – the
     * substitute player has neither a net average nor a handicap yet) a result
     * without reference is returned explicitly; no deviation is flagged and the
     * sheet value is taken without additional confirmation. Otherwise the game
     * needs review exactly when the sheet value differs from the reference
     * value.
     *
     * The stored net value always remains the sheet value (R27.10).
     *
     * @param int|null $referenceValue derived blind reference value or null in
     *        the first evening special case (R27.16)
     */
    public function checkDeviation(int $sheetValue, ?int $referenceValue): DeviationCheck
    {
        if ($referenceValue === null) {
            return DeviationCheck::withoutReference($sheetValue);
        }

        return DeviationCheck::withReference($sheetValue, $referenceValue);
    }

    /**
     * Fills an incomplete team lineup with blind players up to exactly
     * 4 positions (R27.1–3, R21.3, R21.6).
     *
     * Rules:
     * - Real player positions (regular or as a replacement for an absent
     *   player, R27.17) take precedence and stay unchanged.
     * - Missing positions are filled with blind players up to 4 positions; at
     *   most 3 blind players are used and at least 1 real player is kept
     *   (R27.2).
     * - If the team fields no real player at all (empty real lineup), no blind
     *   player is used, the team is given 0 points and the evening counts as
     *   lost (R27.3, R21.6).
     * - The substitute roster player is used as the blind player (R27.4); all
     *   blind positions are filled with them.
     *
     * @param list<int> $realPlayerIds Player ids of the real positions, filled
     *        regularly or as replacement (0–4 entries, R27.17)
     * @param list<RosterPlayer> $roster Roster used to determine the blind
     *        player
     */
    public function fillLineup(array $realPlayerIds, array $roster): FilledLineup
    {
        $realPlayerIds = array_values($realPlayerIds);
        $realCount = count($realPlayerIds);

        // Special case: no real player → lost, 0 points, no blind (R27.3, R21.6).
        if ($realCount === 0) {
            return new FilledLineup([], true, 0);
        }

        $positions = [];
        $position = 1;
        foreach ($realPlayerIds as $playerId) {
            $positions[] = LineupPosition::real($position, $playerId);
            $position++;
        }

        $missing = self::MAX_POSITIONS - $realCount;
        if ($missing > 0) {
            $blindPlayer = $this->determineSubstitutePlayer($roster);
            for ($i = 0; $i < $missing; $i++) {
                $positions[] = LineupPosition::blind(
                    $position,
                    $blindPlayer?->playerId ?? 0,
                    $blindPlayer?->gamesPlayed
                );
                $position++;
            }
        }

        // Points are awarded in the points service; no special case here,
        // so points stay open (null) instead of a fixed 0 (R27.12).
        return new FilledLineup($positions, false, null);
    }

    /**
     * Determines the games played by a player from the roster.
     *
     * @param list<RosterPlayer> $roster
     */
    private function gamesPlayedBy(int $playerId, array $roster): int
    {
        foreach ($roster as $player) {
            if ($player->playerId === $playerId) {
                return $player->gamesPlayed;
            }
        }

        return 0;
    }

    /**
     * Limits a value to the allowed range 0–300 (R27.8–9).
     */
    private function clamp(int $value): int
    {
        return max(self::MIN_VALUE, min(self::MAX_VALUE, $value));
    }
}
