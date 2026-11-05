<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\Import\PlayerImport;
use Bowlingliga\Application\Import\PlayerImportException;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Controller of the player import in the master data (tab "Import"):
 * upload form, preview of the checked file, import after confirmation.
 *
 * The file content travels from the preview to the import as a hidden form
 * field (base64), so no server state is needed; the import checks it again.
 * Access protection and CSRF check happen in the route beforehand.
 */
final class PlayerImportController
{
    /** Largest accepted file. */
    public const MAX_BYTES = 1024 * 1024;

    public function __construct(
        private readonly PlayerImport $import,
        private readonly MasterDataReader $reader,
        private readonly PlayerImportView $view,
        private readonly Translator $t = new Translator(),
    ) {
    }

    /** Upload form for the selected season. */
    public function form(string $csrfField, ?int $seasonId, ?string $error = null): string
    {
        return $this->view->renderForm($csrfField, $this->reader->allSeasons(), $seasonId, $error);
    }

    /**
     * The template file in the visitor's language (R6).
     *
     * @return array{file: string, content: string}
     */
    public function template(): array
    {
        return ['file' => $this->view->templateFileName(), 'content' => $this->view->template()];
    }

    /**
     * Preview of an uploaded file; `$content` is null if no readable file was
     * uploaded.
     *
     * @param array<string, mixed> $input
     */
    public function preview(string $csrfField, array $input, ?string $content): string
    {
        $seasonId = (int) ($input['season_id'] ?? 0);
        if ($content === null || $content === '') {
            return $this->form($csrfField, $seasonId, $this->t->t('import.choose_file'));
        }
        if (strlen($content) > self::MAX_BYTES) {
            return $this->form($csrfField, $seasonId, $this->t->t('import.too_large', ['max' => '1 MB']));
        }

        return $this->view->renderPreview(
            $csrfField,
            $this->import->plan($seasonId, $content),
            $this->seasonName($seasonId),
            $seasonId,
            base64_encode($content),
        )
            . $this->form($csrfField, $seasonId);
    }

    /**
     * Imports the file confirmed in the preview.
     *
     * @param array<string, mixed> $input
     */
    public function import(array $input): ActionResult
    {
        $content = base64_decode((string) ($input['csv'] ?? ''), true);
        if ($content === false || $content === '') {
            return ActionResult::error($this->t->t('import.data_missing'));
        }

        try {
            $result = $this->import->import((int) ($input['season_id'] ?? 0), $content);
        } catch (PlayerImportException $e) {
            return ActionResult::error(implode(' ', [$this->t->error($e), ...array_map($this->t->message(...), $e->errors)]));
        } catch (AccessDeniedException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (\RuntimeException $e) {
            // Domain exceptions of the use cases carry messages for the user.
            return ActionResult::error($this->t->error($e));
        }

        return ActionResult::success($this->t->t('import.done', [
            'players' => $result['players'],
            'teams'   => $result['new_teams'],
            'records' => $result['records'],
        ]));
    }

    private function seasonName(int $seasonId): string
    {
        foreach ($this->reader->allSeasons() as $season) {
            if ((int) $season['id'] === $seasonId) {
                return (string) $season['name'];
            }
        }

        return '';
    }
}
