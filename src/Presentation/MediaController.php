<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\Media\ContentImages;
use Bowlingliga\Domain\Media\ContentImage;
use Bowlingliga\Domain\Media\ContentImageException;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;

/**
 * The admin area "Bilder" and the uploads of the editor (R42).
 *
 * The form of the area and the editor send the same upload; the editor asks
 * for JSON and gets the address of the stored image back.
 */
final class MediaController
{
    /** @var \Closure(string): bool */
    private \Closure $isUploadedFile;

    /**
     * @param \Closure(string): bool|null $isUploadedFile Check that a file came
     *        by HTTP upload; tests pass is_file.
     */
    public function __construct(
        private readonly ContentImages $images,
        private readonly MediaView $view,
        private readonly Translator $t = new Translator(),
        ?\Closure $isUploadedFile = null,
    ) {
        $this->isUploadedFile = $isUploadedFile ?? is_uploaded_file(...);
    }

    public function page(string $csrfField, ?string $message = null, ?string $error = null): string
    {
        try {
            return $this->view->render($csrfField, $this->images->overview(), $message, $error);
        } catch (AccessDeniedException $e) {
            return AdminComponents::pageHeader($this->t->t('admin.area.media.title'), $this->t->t('layout.admin_area'))
                . AdminComponents::notes(null, $this->t->error($e));
        }
    }

    /**
     * Stores an uploaded image.
     *
     * @param array<string, mixed> $file Entry of $_FILES.
     * @return array{ok: bool, location: string|null, message: string}
     */
    public function upload(array $file): array
    {
        try {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw ContentImageException::tooLarge(intdiv(ContentImage::MAX_BYTES, 1024 * 1024));
            }
            $path = (string) ($file['tmp_name'] ?? '');
            if ($error !== UPLOAD_ERR_OK || $path === '' || ! ($this->isUploadedFile)($path)) {
                throw ContentImageException::noFile();
            }

            $location = $this->images->upload((string) file_get_contents($path), (string) ($file['name'] ?? ''));

            return ['ok' => true, 'location' => $location, 'message' => $this->t->t('media.uploaded')];
        } catch (ContentImageException | AccessDeniedException $e) {
            return ['ok' => false, 'location' => null, 'message' => $this->t->error($e)];
        }
    }

    /**
     * @param array<string, mixed> $input `hash`
     */
    public function remove(array $input): ActionResult
    {
        try {
            $this->images->remove((string) ($input['hash'] ?? ''));

            return ActionResult::success($this->t->t('media.removed'));
        } catch (ContentImageException | AccessDeniedException $e) {
            return ActionResult::error($this->t->error($e));
        }
    }

    /**
     * The images for the selection in the editor's image dialog (newest first).
     *
     * @return list<array{title: string, value: string}>
     */
    public function editorList(): array
    {
        try {
            return array_map(
                static fn (array $image): array => ['title' => $image['original_name'], 'value' => $image['url']],
                $this->images->overview(),
            );
        } catch (AccessDeniedException) {
            return [];
        }
    }
}
