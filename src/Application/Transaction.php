<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for a transaction across several write steps of a use case (R4.3).
 *
 * If a step fails, all steps executed up to then are rolled back, so that no
 * partial writes remain. The port is implemented by
 * {@see \Bowlingliga\Persistence\PdoTransaction}; the repositories join a
 * running transaction.
 */
interface Transaction
{
    /**
     * Runs the steps in a common transaction and returns their result; on an
     * exception it rolls back and passes the exception on unchanged.
     *
     * @template T
     *
     * @param callable(): T $steps
     *
     * @return T
     */
    public function perform(callable $steps): mixed;
}
