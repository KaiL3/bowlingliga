<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Generates the top-level navigation of the public area, keeping the selected
 * season (R28.1, R28.2, R28.5, R28.6, R28.15).
 *
 * The navigation contains only the intended top-level items:
 * - home page (season selection, R28.1),
 * - matchdays of the selected season (R28.3),
 * - league table of the selected season (R28.5),
 * - honours of the selected season (R28.6),
 * - rules (R28.15).
 *
 * The detail contents average lists, matchday table and score sheet
 * deliberately do NOT appear as navigation items of their own, but only within
 * the matchday detail view (R28.4).
 *
 * If a season is selected, this component appends its id as query parameter
 * `season` to every item, so that the selection is kept while navigating
 * (R28.2: "of the selected season"): the home page shows the last matchday
 * and the table of that season, the rules its handicap table.
 *
 * Pure, side-effect-free service: equal inputs give the same navigation
 * entries. The context-aware escaping of the values is done by the
 * {@see Layout} renderer on output.
 */
final class PublicNavigation
{
    public function __construct(private readonly \Bowlingliga\I18n\Translator $t = new \Bowlingliga\I18n\Translator())
    {
    }

    /**
     * Returns the top-level navigation entries; with a selected season the
     * items carry the query parameter `season` (R28.2). Callers pass null for
     * the current season, so its links stay free of the parameter and a
     * bookmark follows the current season.
     *
     * @param int|null $seasonId Id of the selected season, or null if no season
     *                           is selected/exists.
     *
     * @return list<array{href: string, text: string, icon: string}>
     */
    public function entries(?int $seasonId): array
    {
        return [
            ['href' => $this->withSeason('/', $seasonId), 'text' => $this->t->t('nav.home'), 'icon' => 'start'],
            ['href' => $this->withSeason('/matchdays', $seasonId), 'text' => $this->t->t('nav.matchdays'), 'icon' => 'matchdays'],
            ['href' => $this->withSeason('/table', $seasonId), 'text' => $this->t->t('nav.table'), 'icon' => 'table'],
            ['href' => $this->withSeason('/honours', $seasonId), 'text' => $this->t->t('nav.honours'), 'icon' => 'honours'],
            ['href' => $this->withSeason('/rules', $seasonId), 'text' => $this->t->t('nav.rules'), 'icon' => 'rules'],
        ];
    }

    /**
     * Appends the season query parameter to a path if a valid season is
     * selected (positive id); otherwise the path stays unchanged.
     */
    private function withSeason(string $pathName, ?int $seasonId): string
    {
        if ($seasonId === null || $seasonId <= 0) {
            return $pathName;
        }

        return $pathName . '?season=' . $seasonId;
    }
}
