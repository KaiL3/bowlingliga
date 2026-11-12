<?php

declare(strict_types=1);

namespace Bowlingliga\Security\Strength;

use ZxcvbnPhp\Zxcvbn;

/**
 * zxcvbn with the {@see CharacterSetScorer}: its matchers find the patterns,
 * random parts count with their character set (R23.16).
 */
final class CharacterSetZxcvbn extends Zxcvbn
{
    public function __construct()
    {
        parent::__construct();
        $this->scorer = new CharacterSetScorer();
    }
}
