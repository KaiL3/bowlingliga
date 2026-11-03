<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\MatchdayManagement;
use Bowlingliga\Application\SeasonPlanning\PlanningPreview;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningManagement;
use Bowlingliga\Domain\SeasonPlanner\Matchday;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;

/**
 * Controller of the season planning and lane assignment in the admin area
 * (task 35.2, R28.11).
 *
 * Connects the use case {@see SeasonPlanningManagement} (tasks 15, 16, 23)
 * and the matchday correction of {@see MatchdayManagement} with the read port
 * {@see SeasonPlanningReader} and the {@see SeasonPlanningView}:
 *
 * - The preview is generated without persisting (R9.14). Season planner and
 *   lane assignment are deterministic; when applying, the preview is
 *   therefore recalculated instead of being kept in the session. A
 *   fingerprint of the displayed preview ensures that exactly the reviewed
 *   plan is saved – if the basis has changed in the meantime, applying is
 *   rejected.
 * - With warnings (R9.12, R18.9) applying requires explicit confirmation;
 *   saving is transactional (R9.3, R9.4, R18.4).
 * - Before the season start, date and type of the matchdays can be corrected
 *   (R9.14, R8.19).
 *
 * CSRF protection (R22.5) is enforced by the calling front controller.
 */
final class SeasonPlanningController
{
    public function __construct(
        private readonly SeasonPlanningManagement $management,
        private readonly MatchdayManagement $matchdayManagement,
        private readonly SeasonPlanningReader $reader,
        private readonly SeasonPlanningView $view,
        private readonly Translator $t = new Translator(),
    ) {
    }

    /**
     * Renders the season planning overview for the selected season.
     */
    public function overview(
        string $csrfField,
        ?int $seasonId,
        ?string $message = null,
        ?string $error = null,
    ): string {
        $season = $seasonId !== null ? $this->reader->season($seasonId) : null;

        if ($season === null) {
            return $this->view->renderOverview($csrfField, null, [], null, $message, $error);
        }

        return $this->view->renderOverview(
            $csrfField,
            $season,
            $this->reader->storedPlan($season['id']),
            $this->reader->reasonAgainstReplanning($season['id']),
            $message,
            $error,
        );
    }

    /**
     * Generates and renders the preview of a schedule including the lane
     * assignment (R9.1, R9.9, R9.14, R18). If no planning is possible, the
     * overview appears with the cause (R9.5, R18.5, R18.6).
     *
     * @param mixed $types Types chosen per matchday number in the preview
     *        (raw form value `types[n]`).
     */
    public function preview(string $csrfField, int $seasonId, ?string $error = null, mixed $types = []): string
    {
        $season = $this->reader->season($seasonId);
        if ($season === null) {
            return $this->overview($csrfField, null, null, $this->t->t('planning.season_not_found'));
        }

        $reason = $this->reader->reasonAgainstReplanning($seasonId);
        if ($reason !== null) {
            return $this->overview($csrfField, $seasonId, null, $this->t->message($reason));
        }

        try {
            $preview = $this->management->planPreview($seasonId, self::types($types));
        } catch (AccessDeniedException | \RuntimeException $e) {
            return $this->overview($csrfField, $seasonId, null, $this->t->error($e));
        }

        return $this->view->renderPreview(
            $csrfField,
            $season,
            $preview,
            $this->reader->teamNames($seasonId),
            self::fingerprint($preview),
            $this->reader->storedPlan($seasonId) !== [],
            $error,
        );
    }

    /**
     * Applies the plan reviewed in the preview (R9.3, R9.12, R18.4).
     *
     * @param array<string, mixed> $input Raw form data (usually $_POST).
     */
    public function apply(array $input): ActionResult
    {
        try {
            $seasonId = $this->integer($input, 'season_id');
            $preview = $this->management->planPreview($seasonId, self::types($input['types'] ?? []));

            if (! hash_equals(self::fingerprint($preview), (string) ($input['fingerprint'] ?? ''))) {
                return ActionResult::error(
                    $this->t->t('planning.basics_changed')
                );
            }

            $this->management->applyPlan($preview, ($input['confirmed'] ?? '') === '1');

            return ActionResult::success($this->t->t('planning.saved', ['count' => count($preview->matchdays)]));
        } catch (AccessDeniedException | ValidationException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (\RuntimeException $e) {
            // Season planning exceptions (R9.4, R9.5, R9.12, R18.5/18.6) already carry
            // messages phrased for the user.
            return ActionResult::error($this->t->error($e));
        }
    }

    /**
     * Corrects the date of a matchday before the season start (R9.14, R8.19).
     * The type stays as planned; it is chosen in the preview, because changing
     * it afterwards would break the round robin.
     *
     * @param array<string, mixed> $input Raw form data (usually $_POST).
     */
    public function correctMatchday(array $input): ActionResult
    {
        try {
            $seasonId   = $this->integer($input, 'season_id');
            $matchdayId = $this->integer($input, 'matchday_id');
            $date      = (string) ($input['date'] ?? '');

            $season = $this->reader->season($seasonId);
            if ($season === null) {
                return ActionResult::error($this->t->t('planning.season_not_found'));
            }
            if ($season['started']) {
                return ActionResult::error($this->t->t('planning.cannot_correct'));
            }

            $matchday = null;
            foreach ($this->reader->storedPlan($seasonId) as $entry) {
                if ($entry['id'] === $matchdayId) {
                    $matchday = $entry;
                }
            }
            if ($matchday === null) {
                return ActionResult::error($this->t->t('planning.matchday_not_in_plan'));
            }

            $this->matchdayManagement->updateMatchday($matchdayId, $date, $matchday['matchday_type']);

            return ActionResult::success(sprintf('Spieltag %d wurde aktualisiert.', $matchday['matchday_number']));
        } catch (AccessDeniedException | ValidationException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (\RuntimeException $e) {
            return ActionResult::error($this->t->error($e));
        }
    }

    /**
     * Valid matchday types per number from the raw form value; anything else
     * is ignored, so the number keeps its default type.
     *
     * @return array<int, string>
     */
    public static function types(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $types = [];
        foreach ($raw as $number => $type) {
            if (is_int($number) && $number > 0 && is_string($type) && in_array($type, Matchday::TYPES, true)) {
                $types[$number] = $type;
            }
        }

        return $types;
    }

    /**
     * Fingerprint of a preview over matchdays, fixtures, lanes and warnings.
     */
    public static function fingerprint(PlanningPreview $preview): string
    {
        return hash('sha256', serialize([$preview->seasonId, $preview->matchdays, $preview->warnings]));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationException if the value is missing or not a positive integer.
     */
    private function integer(array $input, string $field): int
    {
        $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            throw ValidationException::forField($field, 'validation.invalid_value');
        }

        return $value;
    }
}
