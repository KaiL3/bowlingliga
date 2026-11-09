<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;

/**
 * Shows an original score sheet in an overlay on the same page instead of
 * leaving it (R15.6).
 *
 * The link stays a plain link to `/score-sheet/{id}`, so it works without
 * JavaScript; `lightbox.js` opens images in the dialog rendered by
 * {@see dialog()}. PDFs cannot be shown reliably in an overlay on every
 * phone, so they open in a new window, which the link says.
 */
final class Lightbox
{
    public const SCRIPT = '/assets/js/lightbox.js';

    public function __construct(private readonly Translator $t)
    {
    }

    /**
     * Link to an original score sheet: images open in the overlay, PDFs in a
     * new window.
     *
     * @param string $title name of the sheet in the overlay, e.g. the fixture
     */
    public function link(int $id, string $mimeType, string $text, string $title, string $class): string
    {
        $href = '/score-sheet/' . $id;

        if (! str_starts_with(strtolower($mimeType), 'image/')) {
            return sprintf(
                '<a class="%s" href="%s" target="_blank" rel="noopener">%s <span class="link-note">(%s)</span></a>',
                $this->escape($class),
                $href,
                $this->escape($text),
                $this->escape($this->t->t('lightbox.pdf_new_window')),
            );
        }

        return sprintf(
            '<a class="%s js-lightbox" href="%s" data-lightbox-title="%s">%s</a>',
            $this->escape($class),
            $href,
            $this->escape($this->t->t('lightbox.title', ['sheet' => $title])),
            $this->escape($text),
        );
    }

    /** The overlay, once per page, plus its script. */
    public function dialog(): string
    {
        return '<dialog class="lightbox js-lightbox-dialog" aria-labelledby="lightbox-title">'
            . '<div class="lightbox__bar">'
            . '<h2 class="lightbox__title" id="lightbox-title"></h2>'
            . '<button type="button" class="lightbox__close" aria-label="' . $this->escape($this->t->t('lightbox.close')) . '">'
            . '<span aria-hidden="true">×</span></button>'
            . '</div>'
            . '<div class="lightbox__body">'
            . '<img class="lightbox__image" alt="" title="' . $this->escape($this->t->t('lightbox.zoom')) . '">'
            . '</div>'
            . '</dialog>'
            . '<script src="' . $this->escape(Layout::versioned(self::SCRIPT)) . '" defer></script>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
