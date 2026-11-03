<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * A text for the user, not yet in a language: a catalog key and its
 * parameters (see `lang/*.php`). The presentation layer translates it with
 * {@see \Bowlingliga\I18n\Translator::message()}.
 *
 * Conventions for parameters:
 * - a nested Message is translated as well (e.g. the name of a form field),
 * - the parameter `date` holds an ISO date (`YYYY-MM-DD`) and is formatted
 *   in the visitor's language.
 *
 * Immutable value object without I/O, usable in every layer.
 */
final class Message
{
    /** @param array<string, string|int|float|Message> $params */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {
    }
}
