<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\Translatable;

/**
 * Signals a failed server-side input validation (R6.2, R6.3, R7.2, R8.2,
 * R22.4, R29.4, R29.6).
 *
 * The exception names the field concerned ({@see self::$field}) as a catalog
 * key (`field.*`); the message receives it as the parameter `field`, so the
 * presentation layer shows the field's name in the visitor's language.
 */
final class ValidationException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /** Catalog key of the input field concerned (e.g. `field.name`). */
    public readonly string $field;

    /**
     * Creates an exception for an invalid field with a message naming the
     * field.
     *
     * @param string                                  $field  catalog key of the field
     * @param string                                  $key    catalog key of the message
     * @param array<string, string|int|float|Message> $params further parameters
     */
    public static function forField(string $field, string $key, array $params = []): self
    {
        $exception = new self($key, ['field' => new Message($field)] + $params);
        $exception->field = $field;

        return $exception;
    }
}
