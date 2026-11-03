<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\LotDraw\LotDrawException;
use Bowlingliga\Application\LotDraw\LotDrawManagement;
use Bowlingliga\Application\Positionday\PositiondayException;
use Bowlingliga\Application\Positionday\PositiondayManagement;
use Bowlingliga\Application\SeasonAdministration;
use Bowlingliga\Application\SeasonConfiguration;
use Bowlingliga\Domain\Message;
use Bowlingliga\I18n\Languages;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;

/**
 * Admin area "Saison": edit the master data and configuration of a season and
 * form the fixtures of the positiondays (R8.5, R8.16, R12.1–R12.7, R31).
 *
 * - Master data (name, start/end date) can be changed at any time (R8.5).
 * - The configuration only until the season start (R12.7); afterwards it is
 *   only shown.
 * - Positionday fixtures are formed from the standings as soon as all previous
 *   matchdays are played (R31.2–R31.5), and can be formed again as long as no
 *   results have been entered (R31.7).
 * - The fixtures of a lot draw are drawn on site and entered here, as long as
 *   no results have been entered (R32).
 *
 * Writing actions return an {@see ActionResult}; domain errors are translated
 * into messages.
 */
final class SeasonManagementController
{
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    public function __construct(
        private readonly SeasonAdministration $seasonAdministration,
        private readonly PositiondayManagement $positionday,
        private readonly LotDrawManagement $lotDraw,
        private readonly SeasonManagementReader $reader,
        private readonly SeasonManagementView $view,
        private readonly Validator $validator = new Validator(),
        ?callable $clock = null,
        private readonly Translator $t = new Translator(),
        private readonly string $defaultLanguage = Languages::DEFAULT,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('today');
    }

    /**
     * @param array<string, mixed>|null $prefill Form inputs after an error
     */
    public function page(string $csrfField, ?int $seasonId, ?string $message = null, ?string $error = null, ?array $prefill = null): string
    {
        $season = $seasonId !== null ? $this->reader->season($seasonId) : null;
        if ($season === null) {
            return $this->view->withoutSeason();
        }
        // Without a language of its own the season prints in the installation's language.
        $season['score_sheet_language'] = ($season['score_sheet_language'] ?? null) ?? $this->defaultLanguage;

        return $this->view->page(
            $csrfField,
            $season,
            $this->reader->configuration($seasonId),
            $this->reader->positiondays($seasonId),
            $this->isStarted($season['start_date']),
            $message,
            $error,
            $prefill,
            $this->reader->lotDraws($seasonId),
            $this->reader->seasonTeams($seasonId),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateMasterData(array $input): ActionResult
    {
        return $this->perform(function () use ($input): ActionResult {
            $this->seasonAdministration->updateSeason(
                $this->integer($input, 'season_id'),
                $this->text($input, 'name'),
                $this->text($input, 'start_date'),
                $this->text($input, 'end_date'),
                scoreSheetLanguage: $this->text($input, 'score_sheet_language') !== '' ? $this->text($input, 'score_sheet_language') : null,
            );

            return ActionResult::success($this->t->t('season.master_data_saved'));
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateConfiguration(array $input): ActionResult
    {
        return $this->perform(function () use ($input): ActionResult {
            $configuration = SeasonConfiguration::generate(
                $this->validator,
                $this->integer($input, 'lane_count', 'field.lane_count'),
                $this->decimal($input, 'entry_fee', 'field.entry_fee'),
                $this->decimal($input, 'blind_fee', 'field.blind_fee'),
                $this->integer($input, 'max_handicap', 'field.max_handicap'),
                $this->formula($input),
                $this->integer($input, 'target_score', 'season.target_score'),
                $this->integer($input, 'ghost_score', 'field.ghost_pins'),
                $this->integer($input, 'previous_season_matchdays', 'field.previous_matchdays'),
                $this->integer($input, 'positionday_interval', 'field.positionday_interval'),
            );

            $this->seasonAdministration->updateConfiguration($this->integer($input, 'season_id'), $configuration);

            return ActionResult::success($this->t->t('season.configuration_saved'));
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function formPositiondayFixtures(array $input): ActionResult
    {
        return $this->perform(function () use ($input): ActionResult {
            $result = $this->positionday->formAndSave($this->integer($input, 'matchday_id'));

            if (! $result->formed) {
                return ActionResult::error($this->t->message($result->note ?? new Message('positionday.not_ready')));
            }

            $message = $this->t->t('season.positionday_formed');
            if ($result->warnings !== []) {
                $message .= ' ' . $this->t->t('season.note', ['note' => implode(' ', array_map($this->t->message(...), $result->warnings))]);
            }

            return ActionResult::success($message);
        });
    }

    /**
     * Saves the fixtures drawn on site for a lot draw matchday (R32).
     *
     * @param array<string, mixed> $input `matchday_id`, `home[i]`, `away[i]`
     */
    public function saveLotDrawPairings(array $input): ActionResult
    {
        return $this->perform(function () use ($input): ActionResult {
            $home = is_array($input['home'] ?? null) ? $input['home'] : [];
            $away = is_array($input['away'] ?? null) ? $input['away'] : [];
            $pairings = [];
            foreach (array_unique([...array_keys($home), ...array_keys($away)]) as $row) {
                $pairings[] = [(int) ($home[$row] ?? 0), (int) ($away[$row] ?? 0)];
            }

            $this->lotDraw->savePairings($this->integer($input, 'matchday_id'), $pairings);

            return ActionResult::success($this->t->t('season.lot_draw_saved'));
        });
    }

    private function isStarted(string $startDate): bool
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);

        return $start !== false && $start <= ($this->clock)();
    }

    /**
     * Handicap formula from the factor field: empty = without handicap
     * (R12.13), otherwise "HDC = (Zielwert - Durchschnitt) * Faktor" (R12.8).
     *
     * @param array<string, mixed> $input
     */
    private function formula(array $input): ?string
    {
        $text = $this->text($input, 'handicap_factor');
        if ($text === '') {
            return null;
        }

        $factor = $this->decimal($input, 'handicap_factor', 'field.handicap_factor');
        if ($factor <= 0 || $factor > 5) {
            throw ValidationException::forField('field.handicap_factor', 'validation.handicap_factor');
        }

        return 'HDC = (Zielwert - Durchschnitt) * ' . rtrim(rtrim(number_format($factor, 4, '.', ''), '0'), '.');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function integer(array $input, string $field, string $label = 'field.selection'): int
    {
        $value = filter_var($this->text($input, $field), FILTER_VALIDATE_INT);
        if (! is_int($value)) {
            throw ValidationException::forField($label, 'validation.integer');
        }

        return $value;
    }

    /**
     * Decimal number with comma or dot (e.g. "7,50").
     *
     * @param array<string, mixed> $input
     */
    private function decimal(array $input, string $field, string $label): float
    {
        $text = str_replace(',', '.', $this->text($input, $field));
        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            throw ValidationException::forField($label, 'validation.decimal');
        }

        return (float) $text;
    }

    /**
     * @param callable(): ActionResult $action
     */
    private function perform(callable $action): ActionResult
    {
        try {
            return $action();
        } catch (AccessDeniedException | ValidationException | PositiondayException | LotDrawException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (\RuntimeException $e) {
            // Exceptions of the season management carry messages for the user.
            return ActionResult::error($this->t->error($e));
        }
    }
}
