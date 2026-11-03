<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\Transaction;

/** Fake of the {@see Transaction} port that simply runs the steps. */
final class ImmediateTransaction implements Transaction
{
    public int $calls = 0;

    public function perform(callable $steps): mixed
    {
        $this->calls++;

        return $steps();
    }
}
