<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\Cms;

use Bowlingliga\Persistence\RulesRepository;
use Bowlingliga\Persistence\HomePagePostRepository;
use Bowlingliga\Presentation\CmsReader;

/**
 * Production {@see CmsReader} adapter based on the existing raw data
 * repositories (task 35.6).
 *
 * Read-only; the values are passed on unchanged (R4.5).
 */
final class RepositoryCmsReader implements CmsReader
{
    public function __construct(
        private readonly HomePagePostRepository $homePage,
        private readonly RulesRepository $rules,
    ) {
    }

    public function allHomePagePosts(): array
    {
        return $this->homePage->findAllWithId();
    }

    public function rulesText(): ?string
    {
        return $this->rules->findText();
    }
}
