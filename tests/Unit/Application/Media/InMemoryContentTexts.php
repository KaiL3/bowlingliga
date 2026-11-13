<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Media;

use Bowlingliga\Application\Media\ContentTextReader;

/** In-memory fake of the {@see ContentTextReader} port. */
final class InMemoryContentTexts implements ContentTextReader
{
    /** @var list<array{area: string, id: int|null, title: string|null, text: string}> */
    public array $texts = [];

    public function texts(): array
    {
        return $this->texts;
    }
}
