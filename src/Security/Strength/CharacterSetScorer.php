<?php

declare(strict_types=1);

namespace Bowlingliga\Security\Strength;

use ZxcvbnPhp\Matchers\BaseMatch;
use ZxcvbnPhp\Matchers\Bruteforce;
use ZxcvbnPhp\Scorer;

/**
 * Scorer of zxcvbn that rates random parts by the size of their character set
 * instead of ten guesses per character (R23.16).
 *
 * The rating has to happen inside the search for the cheapest sequence of
 * matches: only then does a dictionary word still beat the random reading of
 * the same characters. {@see update()} is the method of zxcvbn-php 1.4
 * (MIT licence, scoring.coffee upstream) with the guesses of a bruteforce
 * match replaced.
 */
final class CharacterSetScorer extends Scorer
{
    protected function update(BaseMatch $match, int $length): void
    {
        $k  = $match->end;
        $pi = $match instanceof Bruteforce ? self::bruteforceGuesses($match) : $match->getGuesses();

        if ($length > 1) {
            $pi *= $this->optimal['pi'][$match->begin - 1][$length - 1];
        }

        $g = $this->factorial($length) * $pi;
        if (! $this->excludeAdditive) {
            $g += pow(self::MIN_GUESSES_BEFORE_GROWING_SEQUENCE, $length - 1);
        }

        foreach ($this->optimal['g'][$k] as $competingL => $competingG) {
            if ($competingL > $length) {
                continue;
            }
            if ($competingG <= $g) {
                return;
            }
        }

        $this->optimal['g'][$k][$length]  = $g;
        $this->optimal['m'][$k][$length]  = $match;
        $this->optimal['pi'][$k][$length] = $pi;

        ksort($this->optimal['g'][$k]);
        ksort($this->optimal['m'][$k]);
        ksort($this->optimal['pi'][$k]);
    }

    /**
     * Guesses for a random token: character set to the power of its length,
     * at least what zxcvbn itself assumes.
     */
    private static function bruteforceGuesses(Bruteforce $match): float
    {
        $guesses = pow(self::characterSet($match->token), mb_strlen($match->token));

        return max(is_finite($guesses) ? $guesses : PHP_FLOAT_MAX, $match->getGuesses());
    }

    /**
     * Size of the character set a token is drawn from: the sum of the classes
     * it uses (26 lower case, 26 upper case, 10 digits, 33 ASCII symbols, 100
     * for anything else), at least the ten of zxcvbn.
     */
    public static function characterSet(string $token): int
    {
        $classes = [
            '/\p{Ll}/u'          => 26,
            '/\p{Lu}/u'          => 26,
            '/[0-9]/'            => 10,
            '/[ -\/:-@\[-`{-~]/' => 33,
            '/[^\x00-\x7F]/u'    => 100,
        ];

        $size = 0;
        foreach ($classes as $pattern => $count) {
            if (preg_match($pattern, $token) === 1) {
                $size += $count;
            }
        }

        return max(10, $size);
    }
}
