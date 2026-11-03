<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;

/**
 * Renders the CMS editing of the admin area (task 35.6, R28.16, R28.17).
 *
 * Covers two interfaces:
 * - editing the home page posts (R33.1–R33.3): list of the existing posts
 *   with edit/remove action and a form for creating or changing,
 * - editing the rules (R34.3, R34.4): a single text form.
 *
 * All writing forms carry a hidden CSRF token following the synchronizer
 * token pattern (R22.5). Form values are escaped context-aware. The stored
 * home page titles are already escaped on saving (R33.11) and are output
 * unchanged in the list – as in the public {@see CmsView}.
 *
 * The renderer is free of side effects and returns HTML fragments; embedding
 * them into the page layout is up to the caller.
 */
final class CmsEditView
{
    use ViewTexts;

    public function __construct(private readonly Translator $t = new Translator())
    {
    }

    /**
     * Renders the editing of the home page posts (R33.1–R33.3).
     *
     * @param string                                               $csrfField Prepared hidden CSRF field (R22.5).
     * @param list<array{id: int, title: string, text: string}>    $contents  Stored (sanitized) posts.
     * @param array{id: int|null, title: string, text: string}|null $form     Raw values to prefill the
     *        form; with `id` it is rendered as edit form, without as create form.
     */
    public function renderHomePageEdit(
        string $csrfField,
        array $contents,
        ?array $form = null,
        ?string $message = null,
        ?string $error = null,
    ): string {
        $items = $contents === []
            ? '<p class="empty">' . $this->text('cms.no_posts') . '</p>'
            : '<ul class="data-list">' . $this->renderContentList($csrfField, $contents) . '</ul>';

        return AdminComponents::pageHeader(
            $this->plain('admin.area.posts.title'),
            $this->plain('cms.home_page_contents'),
            '<p class="page-header__actions"><a class="button button--secondary" href="/">' . $this->text('cms.view_home_page') . '</a></p>',
        )
            . $this->renderNotes($message, $error)
            . '<div class="grid grid--work">'
            . '<section class="card" aria-labelledby="h-home-page-list">'
            . '<div class="card__header"><h2 id="h-home-page-list">' . $this->text('cms.existing') . '</h2></div>'
            . $items
            . '</section>'
            . $this->renderHomePageForm($csrfField, $form)
            . '</div>';
    }

    /**
     * Renders the editing of the rules (R34.3, R34.4).
     *
     * @param string $text Raw value to prefill (stored text or the last
     *                     submitted input).
     */
    public function renderRulesEdit(
        string $csrfField,
        string $text,
        ?string $message = null,
        ?string $error = null,
    ): string {
        $note = $this->text('cms.markup_note');
        $value    = $this->escape($text);

        return AdminComponents::pageHeader(
            $this->plain('admin.area.rules.title'),
            $this->plain('layout.admin_area'),
            '<p class="page-header__actions"><a class="button button--secondary" href="/rules">' . $this->text('cms.view_rules') . '</a></p>',
        )
            . $this->renderNotes($message, $error)
            . <<<HTML
                <section class="card">
                <form class="form form--cms" method="post" action="/admin/rules/save">
                    {$csrfField}
                    <div class="form__field">
                        <label for="rules-text">{$this->text('cms.rules_text', ['max' => 20000])}</label>
                        <textarea id="rules-text" name="text" rows="20" maxlength="20000" aria-describedby="rules-note">{$value}</textarea>
                        <small id="rules-note">{$note}</small>
                    </div>
                    <button type="submit" class="button">{$this->text('cms.save_rules')}</button>
                </form>
                </section>
                HTML;
    }

    /**
     * @param list<array{id: int, title: string, text: string}> $contents
     */
    private function renderContentList(string $csrfField, array $contents): string
    {
        $rows = '';
        foreach ($contents as $content) {
            $id = (int) $content['id'];

            // The title is already escaped on saving (R33.11) – output it unchanged.
            $rows .= sprintf(
                '<li class="data-list__row"><span class="data-list__main">%s</span><span class="data-list__actions">'
                . '<a class="button button--secondary" href="/admin/home-page?edit=%d">%s</a>%s</span></li>',
                $content['title'],
                $id,
                $this->text('common.edit'),
                sprintf(
                    '<form class="inline-form" method="post" action="/admin/home-page/remove">%s'
                    . '<input type="hidden" name="id" value="%d">'
                    . '<button type="submit" class="button button--danger">%s</button></form>',
                    $csrfField,
                    $id,
                    $this->text('common.remove'),
                ),
            );
        }

        return $rows;
    }

    /**
     * @param array{id: int|null, title: string, text: string}|null $form
     */
    private function renderHomePageForm(string $csrfField, ?array $form): string
    {
        $id         = $form['id'] ?? null;
        $title      = $this->escape($form['title'] ?? '');
        $text       = $this->escape($form['text'] ?? '');
        $note    = $this->text('cms.markup_note');
        $edit = $id !== null;

        $heading = $this->text($edit ? 'cms.edit_post' : 'cms.new_post');
        $action       = $edit ? 'update' : 'create';
        $button        = $this->text($edit ? 'common.save_changes' : 'cms.create_post');
        $idField       = $edit ? sprintf('<input type="hidden" name="id" value="%d">', $id) : '';
        $cancel    = $edit
            ? ' <a class="button button--secondary" href="/admin/home-page">' . $this->text('common.cancel') . '</a>'
            : '';

        return <<<HTML
            <section class="card" aria-labelledby="h-home-page-form">
                <div class="card__header"><h2 id="h-home-page-form">{$heading}</h2></div>
                <form class="form form--cms" method="post" action="/admin/home-page/{$action}">
                    {$csrfField}
                    {$idField}
                    <div class="form__field">
                        <label for="home-page-title">{$this->text('cms.title_length', ['min' => 1, 'max' => 200])}</label>
                        <input type="text" id="home-page-title" name="title" maxlength="200" required value="{$title}">
                    </div>
                    <div class="form__field">
                        <label for="home-page-text">{$this->text('cms.text_length', ['min' => 1, 'max' => 5000])}</label>
                        <textarea id="home-page-text" name="text" rows="10" maxlength="5000" required aria-describedby="home-page-note">{$text}</textarea>
                        <small id="home-page-note">{$note}</small>
                    </div>
                    <div class="form__actions"><button type="submit" class="button">{$button}</button>{$cancel}</div>
                </form>
            </section>
            HTML;
    }

    /**
     * Success and error message (each escaped), if present.
     */
    private function renderNotes(?string $message, ?string $error): string
    {
        return AdminComponents::notes($message, $error);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
