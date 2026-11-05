<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\Translatable;
use RuntimeException;
use Throwable;

/**
 * Thrown if a repository operation (typically a transactional write
 * operation) fails (R4.3).
 *
 * After a failed write the operation concerned is rolled back completely, so
 * that no partially written data remains. The error message names the failed
 * operation, so that the calling code can show a meaningful message.
 */
final class RepositoryException extends RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * Creates an exception whose message explicitly names the failed operation
     * (R4.3); `$operation` is the key of its name in the catalogs (`operation.<key>`).
     */
    public static function forOperation(string $operation, Throwable $cause): self
    {
        return new self('error.database_operation', ['operation' => new Message('operation.' . $operation)], $cause);
    }
}
