<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Import;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\Translatable;

/**
 * The import file has errors; nothing was saved.
 */
final class PlayerImportException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /** @var list<Message> one message per faulty line */
    public readonly array $errors;

    /**
     * @param list<Message> $errors
     */
    public static function invalidFile(array $errors): self
    {
        $exception = new self('error.import.invalid_file');
        $exception->errors = $errors;

        return $exception;
    }
}
