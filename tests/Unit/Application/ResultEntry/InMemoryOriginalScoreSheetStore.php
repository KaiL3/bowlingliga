<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\OriginalScoreSheetStore;

/**
 * In-memory fake of the {@see OriginalScoreSheetStore} port for the unit
 * tests, without a database.
 */
final class InMemoryOriginalScoreSheetStore implements OriginalScoreSheetStore
{
    /** @var list<array{fixture_id: int, file: string, mime_type: string}> */
    public array $saved = [];

    private int $nextId = 1;

    public function save(int $fixtureId, string $file, string $mimeType): int
    {
        $this->saved[] = [
            'fixture_id' => $fixtureId,
            'file'          => $file,
            'mime_type'       => $mimeType,
        ];

        return $this->nextId++;
    }
}
