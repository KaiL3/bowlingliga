<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Cms;

use Bowlingliga\Application\Cms\RulesStore;

/**
 * In-memory fake of the {@see RulesStore} port for the CMS unit tests,
 * without a database.
 */
final class InMemoryRulesStore implements RulesStore
{
    public ?string $text = null;

    public function save(string $text): void
    {
        $this->text = $text;
    }
}
