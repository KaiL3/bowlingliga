<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\GhostTeam;

use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\Honours\GameResultInput;
use Bowlingliga\Domain\Points\Lineup;

/**
 * Pure, side-effect-free calculation service for the fixed results of the
 * ghost team (R20).
 *
 * With an odd number of teams the ghost team steps in as opponent. Its players
 * score a fixed net result equal to the target score in every game (R20.1);
 * since the ghost team gets no handicap, the gross of every game equals the
 * net (R20.2). The ghost team lineup always has exactly 4 positions with
 * 3 games each (R21.7).
 *
 * The service is deterministic and accesses neither the database nor any other
 * external state; for the same inputs it always returns the same result.
 *
 * Interaction with the other modules:
 * - The {@see Lineup} produced for the ghost team (4 positions × 3 gross =
 *   target score) can be scored directly by the PointsService, so the ghost
 *   team collects base and bonus points according to R17 (R20.3,
 *   R20.7).
 * - The fixed results do NOT count towards player average, handicap base and
 *   honours (R20.4, R20.5, R20.9). The actual exclusion lives in the
 *   AverageModule and the HonoursModule and is controlled by the `ghostTeam`
 *   flag. This service provides the correspondingly marked input value
 *   objects ({@see self::asAverageGame()}, {@see self::asHonourResult()}).
 * - The ghost team's table row and team points are not affected by this
 *   exclusion (R20.9); the fixed net/gross pins serve this purpose
 *   ({@see self::netPins()}, {@see self::grossPins()}), which are equal for the
 *   ghost team (R16.10, R16.11).
 */
final class GhostTeamService
{
    /** Number of positions of the ghost team lineup (R21.7). */
    public const POSITION_COUNT = 4;

    /** Number of games per position (R20.1). */
    public const GAME_COUNT = 3;

    /**
     * Generates the fixed results of all 4 ghost team positions for the given
     * target score (R20.1, R20.2, R21.7).
     *
     * @param int $targetScore Globally defined target score (0–300).
     *
     * @return list<GhostTeamResult> Exactly 4 position results.
     *
     * @throws \InvalidArgumentException If the target score is outside 0–300.
     */
    public function results(int $targetScore): array
    {
        $results = [];
        for ($position = 0; $position < self::POSITION_COUNT; $position++) {
            $results[] = new GhostTeamResult($targetScore);
        }

        return $results;
    }

    /**
     * Generates the ghost team {@see Lineup} for the points calculation (R20.3,
     * R20.7): exactly 4 positions with 3 gross values each, equal to the target
     * score.
     *
     * Since gross = net applies to the ghost team (R20.2), all gross values of
     * the lineup equal the target score. The ghost team always counts as fully
     * lined up (on par with a real player), so the PointsService awards it
     * points regularly and it can win a game (R20.7).
     *
     * @param int $targetScore Globally defined target score (0–300).
     *
     * @throws \InvalidArgumentException If the target score is outside 0–300.
     */
    public function lineup(int $targetScore): Lineup
    {
        if ($targetScore < 0 || $targetScore > 300) {
            throw new \InvalidArgumentException(
                'The ghost score of the ghost team must be between 0 and 300, given: ' . $targetScore
            );
        }

        $positions = [];
        for ($position = 0; $position < self::POSITION_COUNT; $position++) {
            $positions[] = array_fill(0, self::GAME_COUNT, $targetScore);
        }

        // The ghost team counts as fully staffed and takes part regularly in the
        // points scoring (R20.7).
        return new Lineup($positions, true);
    }

    /**
     * Net pins of the ghost team for an evening: 4 positions × 3 games ×
     * target score (R16.10, R16.11, R20.8).
     *
     * @param int $targetScore Globally defined target score (0–300).
     */
    public function netPins(int $targetScore): int
    {
        return self::POSITION_COUNT * self::GAME_COUNT * $targetScore;
    }

    /**
     * Gross pins of the ghost team for an evening. Since the ghost team gets no
     * handicap (R20.2), gross pins equal net pins (R16.11).
     *
     * @param int $targetScore Globally defined target score (0–300).
     */
    public function grossPins(int $targetScore): int
    {
        // R16.11 / R20.2: gross pins = net pins (no handicap).
        return $this->netPins($targetScore);
    }

    /**
     * Creates a {@see Game} marked as ghost team for the AverageModule. The
     * module excludes such games from the average via the `ghostTeam` flag
     * (R20.4).
     *
     * @param int $targetScore Fixed net result of the ghost team player.
     *
     * @throws \InvalidArgumentException If the target score is outside 0–300.
     */
    public function asAverageGame(int $targetScore): Game
    {
        return new Game(
            netResult: $targetScore,
            blind: false,
            ghostTeam: true,
            previousSeason: false,
        );
    }

    /**
     * Creates a {@see GameResultInput} marked as ghost team for the
     * HonoursModule. The module excludes such results from the honours via the
     * `ghostTeam` flag (R20.5).
     *
     * Since the ghost team gets no handicap (R20.2), the handicap is set to 0
     * (gross = net).
     *
     * @param int    $playerId    Id of the ghost team position.
     * @param Gender $gender      Formally required gender; no domain effect
     *                            since excluded.
     * @param int    $eveningId   Id of the evening.
     * @param int    $targetScore Fixed net result (0–300).
     *
     * @throws \InvalidArgumentException If the target score is outside 0–300.
     */
    public function asHonourResult(
        int $playerId,
        Gender $gender,
        int $eveningId,
        int $targetScore,
    ): GameResultInput {
        return new GameResultInput(
            playerId: $playerId,
            gender: $gender,
            eveningId: $eveningId,
            netResult: $targetScore,
            handicap: 0,
            blind: false,
            ghostTeam: true,
        );
    }
}
