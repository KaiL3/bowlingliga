<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\CmsReader;
use Bowlingliga\Tests\Unit\Application\Cms\InMemoryRulesStore;
use Bowlingliga\Tests\Unit\Application\Cms\InMemoryHomePagePostStore;

/**
 * In-memory fake of the {@see CmsReader} port that reads directly from the
 * in-memory stores of the CMS use cases.
 */
final class InMemoryCmsReader implements CmsReader
{
    public function __construct(
        private readonly InMemoryHomePagePostStore $homePage,
        private readonly InMemoryRulesStore $rules,
    ) {
    }

    public function allHomePagePosts(): array
    {
        $contents = [];
        foreach ($this->homePage->contents as $id => $content) {
            $contents[] = ['id' => $id, 'title' => $content['title'], 'text' => $content['text']];
        }

        // Newest first, as in the production repository.
        return array_reverse($contents);
    }

    public function rulesText(): ?string
    {
        return $this->rules->text;
    }
}
