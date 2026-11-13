<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Cms;

use Bowlingliga\Application\Media\ContentImageStore;
use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Security\LocalImageUriFilter;
use Bowlingliga\Security\ValidationException;

/**
 * Checks the images of a text before it is saved (R42.4, R42.6): every image
 * has a description and comes from the website's own image store – an
 * address of the form `/media/<hash>.jpg|png` of an image that exists there.
 *
 * The sanitizer would drop any other image silently; refusing the text
 * instead keeps the input and names the image.
 */
final class TextImages
{
    /** Longest part of an address quoted in the message. */
    private const QUOTED = 80;

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly ?ContentImageStore $images = null,
    ) {
    }

    /**
     * @throws ValidationException for an image without description or from
     *         elsewhere; nothing is saved then.
     */
    public function check(string $rawText, string $field): void
    {
        foreach ($this->sanitizer->images($rawText) as $image) {
            if (trim($image['alt']) === '') {
                throw ValidationException::forField($field, 'validation.image_alt');
            }

            $source = trim($image['src']);
            if (preg_match(LocalImageUriFilter::SOURCE_PATTERN, $source, $match) !== 1
                || ($this->images !== null && $this->images->find($match[1]) === null)) {
                throw ValidationException::forField($field, 'validation.image_source', [
                    'source' => mb_strlen($source) > self::QUOTED ? mb_substr($source, 0, self::QUOTED) . '…' : $source,
                ]);
            }
        }
    }
}
