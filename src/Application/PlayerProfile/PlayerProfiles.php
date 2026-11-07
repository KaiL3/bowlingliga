<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PlayerProfile;

use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\PlayerProfile\PlayerHistory;
use Bowlingliga\Domain\PlayerProfile\PlayerHistoryService;

/**
 * Reading use case of the player page (R39): chooses the season and builds
 * the history and the team history from the raw data.
 */
final class PlayerProfiles
{
    public function __construct(
        private readonly PlayerProfileReader $reader,
        private readonly PlayerHistoryService $histories = new PlayerHistoryService(),
    ) {
    }

    /**
     * The player page, or null for an unknown player.
     *
     * Season (R39.2): the requested one, otherwise the current one – each only
     * if the player has played or is on a roster there –, otherwise their
     * latest season.
     */
    public function profile(int $playerId, ?int $requestedSeasonId, ?int $currentSeasonId): ?PlayerProfile
    {
        $player = $this->reader->player($playerId);
        if ($player === null) {
            return null;
        }

        $summaries = [];
        $histories = [];
        $previous  = [];
        foreach ($this->reader->seasons($playerId) as $season) {
            $raw     = $this->reader->seasonRawData($playerId, $season['season_id']);
            $history = $this->histories->history($raw->matchdays, $raw->previousSeasonGames, $raw->record, $raw->previousSeasonMatchdays);
            $previous[$season['season_id']] = self::previousSeasonUntil($raw);
            $histories[$season['season_id']] = $history;
            $summaries[] = new PlayerSeasonSummary(
                $season['season_id'],
                $season['season_name'],
                $season['team_season_id'],
                $season['team_name'],
                $history->matchdaysPlayed(),
                $history->games(),
                $history->lastAverage(),
                $history->lastHandicap(),
            );
        }

        $selected = null;
        foreach ([$requestedSeasonId, $currentSeasonId] as $candidate) {
            if ($candidate !== null && isset($histories[$candidate])) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null && $summaries !== []) {
            $selected = $summaries[count($summaries) - 1]->seasonId;
        }

        return new PlayerProfile(
            $player['id'],
            $player['name'],
            $player['gender'],
            array_reverse($summaries),
            $selected,
            $selected !== null ? $histories[$selected] : new PlayerHistory(),
            $selected !== null ? $previous[$selected] : null,
        );
    }

    /**
     * Last matchday counter whose average includes the previous season, null
     * if no previous season counts (R11.3, R11.4).
     */
    private static function previousSeasonUntil(PlayerSeasonRawData $raw): ?int
    {
        $games = array_filter($raw->previousSeasonGames, static fn (Game $game): bool => $game->isActuallyPlayed());
        $counts = $games !== [] || ($raw->record !== null && $raw->record->isIncludable());

        return $counts && $raw->previousSeasonMatchdays > 0 ? $raw->previousSeasonMatchdays : null;
    }
}
