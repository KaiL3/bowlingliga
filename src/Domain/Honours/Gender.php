<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Honours;

/**
 * Gender of a player, by which the honours are determined separately
 * (R13.1).
 *
 * The honours are calculated per category separately for the genders "male"
 * and "female"; each result competes for places 1 to 3 only within its own
 * gender (R13.1).
 */
enum Gender: string
{
    /** Male player (R13.1). */
    case MALE = 'male';

    /** Weiblicher Spieler (R13.1). */
    case FEMALE = 'female';
}
