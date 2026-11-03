<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\Cms\HomePagePostManagement;
use Bowlingliga\Application\Cms\RulesManagement;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;

/**
 * Controller of the CMS editing in the admin area (task 35.6, R28.16, R28.17).
 *
 * Connects the writing CMS use cases (task 29.1/29.2) with the read port
 * {@see CmsReader} and the {@see CmsEditView}. The controller:
 * - renders the editing interfaces for home page posts and rules,
 * - processes the writing actions via the use cases, which in turn validate
 *   (R33.5, R33.6, R34.5), sanitize (R33.11, R34.8) and check write access
 *   (R5.1, R33.10, R34.7),
 * - translates domain exceptions into success or error messages for the
 *   user.
 *
 * CSRF protection (R22.5) is enforced by the calling front controller before
 * an action runs.
 */
final class CmsEditController
{
    public const HOME_PAGE_ACTIONS = ['create', 'update', 'remove'];

    public function __construct(
        private readonly HomePagePostManagement $homePageManagement,
        private readonly RulesManagement $rulesManagement,
        private readonly CmsReader $reader,
        private readonly CmsEditView $view,
        private readonly Translator $t = new Translator(),
    ) {
    }

    /**
     * Renders the editing of the home page posts.
     *
     * @param int|null                  $editId Id of the post to edit (prefill the form).
     * @param array<string, mixed>|null $input  Last submitted form data; after an
     *                                          error the form is prefilled with it, so
     *                                          that no input is lost.
     */
    public function homePageEdit(
        string $csrfField,
        ?int $editId = null,
        ?array $input = null,
        ?string $message = null,
        ?string $error = null,
    ): string {
        $contents  = $this->reader->allHomePagePosts();
        $form = null;

        if ($input !== null) {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            $form = [
                'id'    => is_int($id) && $id > 0 ? $id : null,
                'title' => (string) ($input['title'] ?? ''),
                'text'  => (string) ($input['text'] ?? ''),
            ];
        } elseif ($editId !== null) {
            $content = $this->findContent($contents, $editId);
            if ($content === null) {
                $error ??= $this->t->t('cms.post_not_found');
            } else {
                // The title is stored escaped (R33.11); for editing
                // the raw text is recovered so that it is not escaped twice.
                $form = [
                    'id'    => $editId,
                    'title' => html_entity_decode($content['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'text'  => $content['text'],
                ];
            }
        }

        return $this->view->renderHomePageEdit($csrfField, $contents, $form, $message, $error);
    }

    /**
     * Renders the editing of the rules.
     *
     * @param string|null $inputText Last submitted text; after an error the form
     *                               is prefilled with it.
     */
    public function rulesEdit(
        string $csrfField,
        ?string $inputText = null,
        ?string $message = null,
        ?string $error = null,
    ): string {
        $text = $inputText ?? $this->reader->rulesText() ?? '';

        return $this->view->renderRulesEdit($csrfField, $text, $message, $error);
    }

    /**
     * Runs a writing action on the home page posts (`anlegen`, `aendern`,
     * `entfernen`).
     *
     * @param array<string, mixed> $input Raw form data (usually $_POST).
     */
    public function processHomePage(string $action, array $input): ActionResult
    {
        return $this->perform(fn (): ActionResult => match ($action) {
            'create'   => $this->homePageCreate($input),
            'update'   => $this->homePageUpdate($input),
            'remove' => $this->homePageRemove($input),
            default     => ActionResult::error('Unbekannte Aktion.'),
        });
    }

    /**
     * Saves the rules text (R34.3, R34.4).
     *
     * @param array<string, mixed> $input Raw form data (usually $_POST).
     */
    public function saveRules(array $input): ActionResult
    {
        return $this->perform(function () use ($input): ActionResult {
            $this->rulesManagement->save((string) ($input['text'] ?? ''));

            return ActionResult::success($this->t->t('cms.rules_saved'));
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    private function homePageCreate(array $input): ActionResult
    {
        $this->homePageManagement->add(
            (string) ($input['title'] ?? ''),
            (string) ($input['text'] ?? ''),
        );

        return ActionResult::success($this->t->t('cms.post_created'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function homePageUpdate(array $input): ActionResult
    {
        $id = $this->existingId($input);

        $this->homePageManagement->change(
            $id,
            (string) ($input['title'] ?? ''),
            (string) ($input['text'] ?? ''),
        );

        return ActionResult::success($this->t->t('cms.post_changed'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function homePageRemove(array $input): ActionResult
    {
        $this->homePageManagement->remove($this->existingId($input));

        return ActionResult::success($this->t->t('cms.post_removed'));
    }

    /**
     * Runs an action and translates domain exceptions into an error message;
     * the data then stays unchanged.
     *
     * @param callable(): ActionResult $action
     */
    private function perform(callable $action): ActionResult
    {
        try {
            return $action();
        } catch (AccessDeniedException | ValidationException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (\RuntimeException $e) {
            // Repository errors (R4.3) and missing HTML sanitizing (R33.11)
            // already carry messages phrased for the user.
            return ActionResult::error($this->t->error($e));
        }
    }

    /**
     * Reads the id of an existing home page post from the form data.
     *
     * @param array<string, mixed> $input
     *
     * @throws ValidationException if the id is missing, invalid or no such post
     *         exists.
     */
    private function existingId(array $input): int
    {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0
            || $this->findContent($this->reader->allHomePagePosts(), $id) === null
        ) {
            throw ValidationException::forField('field.post', 'cms.post_not_found');
        }

        return $id;
    }

    /**
     * @param list<array{id: int, title: string, text: string}> $contents
     *
     * @return array{id: int, title: string, text: string}|null
     */
    private function findContent(array $contents, int $id): ?array
    {
        foreach ($contents as $content) {
            if ((int) $content['id'] === $id) {
                return $content;
            }
        }

        return null;
    }
}
