<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Media\ContentImage;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * Renders the admin area "Bilder" (R42): uploading an image and the overview
 * of all images with size, date and the texts that use them; an unused image
 * can be removed.
 */
final class MediaView
{
    use ViewTexts;

    private readonly Formatter $format;

    public function __construct(private readonly Translator $t = new Translator())
    {
        $this->format = new Formatter($t);
    }

    /**
     * @param list<array{hash: string, url: string, mime_type: string, width: int, height: int, size_bytes: int, original_name: string, uploaded_at: string, used_in: list<array{area: string, id: int|null, title: string|null}>}> $images
     */
    public function render(string $csrfField, array $images, ?string $message = null, ?string $error = null): string
    {
        $max = intdiv(ContentImage::MAX_BYTES, 1024 * 1024);

        $upload = AdminComponents::card($this->plain('media.upload_title'), <<<HTML
            <form class="form" method="post" action="/admin/media/upload" enctype="multipart/form-data">
                {$csrfField}
                <div class="form__field">
                    <label for="media-file">{$this->text('media.file')}</label>
                    <input type="file" id="media-file" name="file" accept="image/jpeg,image/png,image/webp" required aria-describedby="media-file-note">
                    <small id="media-file-note">{$this->text('media.file_note', ['max' => $max, 'edge' => ContentImage::MAX_EDGE])}</small>
                </div>
                <button type="submit" class="button">{$this->text('media.upload')}</button>
            </form>
            <p class="empty">{$this->text('media.editor_note')}</p>
            HTML);

        $items = '';
        foreach ($images as $image) {
            $items .= $this->item($csrfField, $image);
        }
        $list = $items === ''
            ? '<p class="empty">' . $this->text('media.none') . '</p>'
            : '<ul class="media-grid">' . $items . '</ul>';

        return AdminComponents::pageHeader($this->plain('admin.area.media.title'), $this->plain('layout.admin_area'))
            . AdminComponents::notes($message, $error)
            . '<div class="grid">' . $upload
            . AdminComponents::card($this->plain('media.all', ['count' => count($images)]), $list)
            . '</div>';
    }

    /**
     * @param array{hash: string, url: string, mime_type: string, width: int, height: int, size_bytes: int, original_name: string, uploaded_at: string, used_in: list<array{area: string, id: int|null, title: string|null}>} $image
     */
    private function item(string $csrfField, array $image): string
    {
        $name = $this->e($image['original_name']);
        $url  = $this->e($image['url']);
        $meta = $this->e(sprintf(
            '%d × %d px · %s · %s',
            $image['width'],
            $image['height'],
            $this->size($image['size_bytes']),
            $this->format->date(substr($image['uploaded_at'], 0, 10)),
        ));

        if ($image['used_in'] === []) {
            $usage = '<p class="empty">' . $this->text('media.unused') . '</p>'
                . sprintf(
                    '<form class="inline-form" method="post" action="/admin/media/remove">%s'
                    . '<input type="hidden" name="hash" value="%s">'
                    . '<button type="submit" class="button button--danger" aria-label="%s">%s</button></form>',
                    $csrfField,
                    $this->e($image['hash']),
                    $this->text('media.remove_label', ['name' => $image['original_name']]),
                    $this->text('common.remove'),
                );
        } else {
            $places = '';
            foreach ($image['used_in'] as $place) {
                $places .= '<li>' . $this->place($place) . '</li>';
            }
            $usage = '<p class="media-item__label">' . $this->text('media.used_in') . '</p><ul class="media-item__places">' . $places . '</ul>';
        }

        return <<<HTML
            <li class="media-item">
                <a class="media-item__preview" href="{$url}" target="_blank" rel="noopener"><img src="{$url}" alt="{$name}" loading="lazy" width="{$image['width']}" height="{$image['height']}"></a>
                <p class="media-item__name">{$name}</p>
                <p class="media-item__meta">{$meta}</p>
                {$usage}
            </li>
            HTML;
    }

    /**
     * A text that uses the image, linked to where it is edited.
     *
     * @param array{area: string, id: int|null, title: string|null} $place
     */
    private function place(array $place): string
    {
        return match ($place['area']) {
            // The title of a post is stored escaped (R33.11).
            'home_page' => sprintf('<a href="/admin/home-page?edit=%d">%s</a>', (int) $place['id'], $this->text('media.place.home_page', [], ['title' => (string) $place['title']])),
            'rules'     => '<a href="/admin/rules">' . $this->text('media.place.rules') . '</a>',
            default     => $this->text('media.place.' . $place['area']),
        };
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
