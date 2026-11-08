<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\PublicSite\PublicDataReader;

/**
 * Controller of the public (reading) views (R1, R2, R13, R14, R15, R16).
 *
 * Connects the {@see PublicDataReader} with the {@see PublicView} and
 * encapsulates the visibility and error logic: the detail view of a matchday
 * is only shown for played matchdays (R15.5); if a matchday does not exist or
 * its data cannot be retrieved, the intended message appears (R1.5, R15.5).
 * All views are read-only and require no login (R1.4, R15.2); without public
 * player data (R40) the caller passes `$withPlayers = false` and only team
 * values appear.
 *
 * The controller returns the fully rendered content (HTML fragment); embedding
 * it into the responsive page layout and delivering it is up to the caller via
 * the {@see Layout}.
 */
final class PublicController
{
    /** @var callable(): string today as Y-m-d */
    private $today;

    public function __construct(
        private readonly PublicDataReader $reader,
        private readonly PublicView $view,
        ?callable $today = null,
        private readonly \Bowlingliga\I18n\Translator $t = new \Bowlingliga\I18n\Translator(),
    ) {
        $this->today = $today ?? static fn (): string => date('Y-m-d');
    }

    /**
     * Renders a season's matchday list (R14).
     */
    /**
     * Home page: last played matchday and league table of the season, plus
     * posts and season selection (R33.7–R33.9).
     *
     * @param string                                    $messages already rendered posts
     * @param list<array{id: int|string, name: string}> $seasons  all seasons, ascending by start
     */
    public function homePage(?int $seasonId, string $messages, array $seasons): string
    {
        $lastOne   = null;
        $table   = null;
        $seasonName = null;

        if ($seasonId !== null) {
            foreach ($seasons as $season) {
                if ((int) $season['id'] === $seasonId) {
                    $seasonName = (string) $season['name'];
                }
            }

            $playedOnes = array_filter(
                $this->reader->matchdayList($seasonId),
                static fn ($entry): bool => $entry->isPlayed
            );
            if ($playedOnes !== []) {
                $lastOne = $this->reader->matchdayDetail(end($playedOnes)->matchdayId);
            }

            $table = $this->reader->leagueTable($seasonId);
            $next = $this->reader->nextMatchday($seasonId, ($this->today)());
        }

        return $this->view->renderHomePage($messages, $lastOne, $table, $seasons, $seasonName, $next ?? null, $seasonId);
    }

    public function matchdayList(int $seasonId): string
    {
        // R14.8: the next matchday with fixtures and lanes as first tab.
        return $this->view->renderMatchdayList(
            $this->reader->matchdayList($seasonId),
            $this->reader->nextMatchday($seasonId, ($this->today)()),
        );
    }

    /**
     * Renders the detail view of a matchday if it is played and retrievable
     * (R15.1); otherwise the matching message (R15.5, R1.5).
     */
    public function matchdayDetail(int $matchdayId, bool $withPlayers = true): string
    {
        // R15.5: detail view only for played matchdays.
        if (! $this->reader->isMatchdayPlayed($matchdayId)) {
            return $this->message('public.not_played_yet');
        }

        $detail = $this->reader->matchdayDetail($matchdayId);
        if ($detail === null) {
            // R1.5: the matchday does not exist or the data cannot be retrieved.
            return $this->message('public.results_unavailable');
        }

        return $this->view->renderMatchdayDetail($detail, $withPlayers);
    }

    /**
     * Renders a season's league table (R16); a message if the data cannot be
     * retrieved.
     */
    public function leagueTable(int $seasonId): string
    {
        $table = $this->reader->leagueTable($seasonId);
        if ($table === null) {
            return $this->message('public.table_unavailable');
        }

        return $this->view->renderLeagueTable($table);
    }

    /**
     * Renders the team page with the current average list (R2); unknown teams
     * and the ghost team get a message (R1.5).
     */
    public function teamAverageList(int $teamSeasonId, bool $withPlayers = true): string
    {
        $team = $this->reader->teamAverageList($teamSeasonId);
        if ($team === null) {
            return $this->message('public.team_not_found');
        }

        return $this->view->renderTeamAverageList($team, $withPlayers);
    }

    /**
     * Renders a season's honours (R13); the public visibility (after the end
     * date) is checked by the view (R13.8, R13.10).
     */
    public function honours(int $seasonId, bool $withPlayers = true): string
    {
        $honours = $this->reader->honours($seasonId);
        if ($honours === null) {
            return $this->message('public.honours_unavailable');
        }

        return $this->view->renderHonours($honours, $withPlayers);
    }

    /**
     * Renders a simple note/error message (catalog key) as HTML fragment
     * (R1.5, R15.5).
     */
    private function message(string $key): string
    {
        return sprintf(
            '<p class="notice" role="status">%s</p>',
            htmlspecialchars($this->t->t($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
