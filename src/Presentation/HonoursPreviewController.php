<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\Aggregation\HonoursAggregator;
use Bowlingliga\Application\SeasonReader;
use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\WriteAccess;

/**
 * Honours preview in the admin area (task 35.5, R28.14, R13.9).
 *
 * As long as a season's end date has not passed, the calculated honours are
 * only accessible to administrators (R13.8, R13.9). The preview uses the same
 * calculation ({@see HonoursAggregator}) and view as the public page; the
 * visibility is checked via
 * {@see \Bowlingliga\Domain\Honours\Honours::isVisibleFor()} with the admin
 * status from {@see WriteAccess}. A note shows whether and from when the
 * honours are publicly visible (R13.10).
 */
final class HonoursPreviewController
{
    use ViewTexts;

    private readonly Formatter $format;

    public function __construct(
        private readonly HonoursAggregator $aggregator,
        private readonly SeasonReader $seasons,
        private readonly PublicView $view,
        private readonly WriteAccess $writeAccess,
        private readonly Translator $t = new Translator(),
    ) {
        $this->format = new Formatter($t);
    }

    /**
     * Renders the honours preview of the selected season.
     */
    public function preview(?int $seasonId): string
    {
        $season = $seasonId !== null ? $this->seasons->find($seasonId) : null;
        if ($season === null) {
            return AdminComponents::pageHeader($this->plain('admin.area.honours.title'), $this->plain('layout.admin_area'))
                . '<p class="notice">' . $this->text('honours_preview.no_season') . '</p>';
        }

        $honours = $this->aggregator->honours((int) $season['id']);

        if (! $honours->isVisibleFor($this->writeAccess->checkWriteAccess())) {
            // R13.8/R13.9: without an admin session nothing is shown before the end date.
            return AdminComponents::pageHeader($this->plain('admin.area.honours.title'), $this->plain('layout.admin_area'))
                . '<p class="notice">' . $this->text('public.honours_later') . '</p>';
        }

        $name = isset($season['name']) ? (string) $season['name'] : $this->plain('layout.season') . ' ' . (int) $season['id'];

        $status = $honours->isPubliclyVisible()
            ? '<p class="notice" role="status">' . $this->text('honours_preview.public', [], ['link' => sprintf('<a href="/honours?season=%d">%s</a>', (int) $season['id'], $this->text('honours_preview.publicly_visible'))]) . '</p>'
            : '<p class="notice" role="status">' . $this->text('honours_preview.preview', ['date' => $this->format->date((string) $season['end_date'])]) . '</p>';

        $categories = $this->hasOccupiedPlaces($honours)
            ? $this->view->renderHonourCategories($honours)
            : '<p class="empty">' . $this->text('honours_preview.no_results') . '</p>';

        return AdminComponents::pageHeader($name, $this->plain('admin.area.honours.title')) . $status . $categories;
    }

    private function hasOccupiedPlaces(Honours $honours): bool
    {
        foreach ($honours->categoryResults as $result) {
            foreach ($result->places as $place) {
                if (! $place->isVacant()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
