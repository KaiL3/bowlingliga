<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

use Bowlingliga\Domain\AverageList\AverageList;
use Bowlingliga\Domain\AverageList\AverageListService;
use Bowlingliga\Domain\AverageList\AverageListPlayerInput;

/**
 * Aggregates a team's raw data into an average list as of a matchday (R2.1,
 * R2.4, R27.13, R20.4).
 *
 * The aggregator reads the player raw data via the
 * {@see AverageListRawDataReader}, applies the domain exclusion rule – only
 * games actually played by the player are included, i.e. neither games marked
 * blind (R27.13) nor ghost team results (R20.4) – and passes the remaining net
 * values together with the frozen handicap to the pure
 * {@see AverageListService}, which calculates the average list from them.
 *
 * A player without an includable game gets no row; if the whole team has no
 * includable games up to this matchday, the result is an empty average list
 * (R2.4). The calculation is reproducible (R35.2): the aggregation itself is
 * free of side effects; its only dependency is the reading raw data port.
 */
final class AverageListAggregator
{
    public function __construct(
        private readonly AverageListRawDataReader $reader,
        private readonly AverageListService $averageLists = new AverageListService(),
    ) {
    }

    /**
     * Builds a team's average list as of the given matchday, inclusive (R2.1).
     */
    public function averageList(int $teamSeasonId, int $toMatchdayCounter): AverageList
    {
        $inputs = [];

        foreach ($this->reader->findPlayerRawData($teamSeasonId, $toMatchdayCounter) as $player) {
            // Only include games actually played by the player (R2.1, R27.13,
            // R20.4); the order is kept for the best series (R2.12).
            $netResults = [];
            foreach ($player->games as $game) {
                if ($game->isIncludable()) {
                    $netResults[] = $game->net;
                }
            }

            // Players without an includable game get no row (R2.4).
            if ($netResults === []) {
                continue;
            }

            $inputs[] = new AverageListPlayerInput(
                $player->playerId,
                $player->playerName,
                $player->gender,
                $netResults,
                $player->frozenHandicap,
            );
        }

        return $this->averageLists->averageList($inputs);
    }
}
