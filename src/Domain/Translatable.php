<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * An error whose text for the user is a {@see Message}; the presentation
 * layer shows it in the visitor's language instead of `getMessage()`, which
 * only carries the key (for logs).
 */
interface Translatable
{
    public function message(): Message;
}
