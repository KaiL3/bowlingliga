<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Security\Strength\CharacterSetScorer;
use Bowlingliga\Security\Strength\CharacterSetZxcvbn;

/**
 * Estimates the strength of a password in bits (R23.16), comparable to the
 * quality estimation of KeePass.
 *
 * The pattern search of zxcvbn finds what an attacker tries first: words and
 * names of its dictionaries and the given personal words (username, league),
 * keyboard rows, sequences, repetitions, dates and l33t spellings. Random
 * parts count with the size of their character set, as in KeePass
 * ({@see CharacterSetScorer}). The result is log2 of the estimated number of
 * guesses, rounded down.
 *
 * Only the first 64 characters go through the pattern search (its effort
 * grows with the length); every further character adds the bits of the
 * character set of the rest.
 */
final class PasswordStrength
{
    /** Characters that go through the pattern search. */
    private const ANALYSED_LENGTH = 64;

    private ?CharacterSetZxcvbn $zxcvbn = null;

    /**
     * @param list<string> $personalWords Words an attacker would try first
     *        for this account (username, e-mail address, league name).
     */
    public function bits(string $password, array $personalWords = []): int
    {
        if ($password === '') {
            return 0;
        }

        $analysed = mb_substr($password, 0, self::ANALYSED_LENGTH);
        $rest     = mb_substr($password, self::ANALYSED_LENGTH);

        $result = ($this->zxcvbn ??= new CharacterSetZxcvbn())->passwordStrength(
            $analysed,
            array_values(array_filter($personalWords, static fn (string $word): bool => trim($word) !== '')),
        );

        $bits = (float) $result['guesses_log10'] * log(10, 2);
        if ($rest !== '') {
            $bits += mb_strlen($rest) * log(CharacterSetScorer::characterSet($rest), 2);
        }

        return max(0, (int) floor($bits));
    }
}
