<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Server-side input validation for writing use cases (cross-cutting,
 * task 19).
 *
 * Bundles the recurring range and format checks in one place, so that all
 * writing use cases validate consistently before raw data is persisted:
 * - Name 1–100 characters (R6.2, R7.2).
 * - Gender only "männlich" or "weiblich" (R6.3).
 * - Net result as integer in the range 0–300 (R22.4).
 * - Lane count as even integer ≥ 2 (R8.2/R8.11).
 * - Amount of money ≥ 0.00 (R8.2).
 * - CMS text length within an upper limit (R33.11/R34.8).
 *
 * Pure service without persistence and without hidden state: equal inputs
 * always give the same result. For invalid inputs a
 * {@see ValidationException} naming the field concerned is thrown; the valid
 * (possibly normalised) values are returned.
 */
final class Validator
{
    /** Valid gender values according to the DB ENUM and R6.3. */
    public const GENDER_MALE = 'male';
    public const GENDER_FEMALE  = 'female';

    private const NAME_MIN = 1;
    private const NAME_MAX = 100;

    private const NET_MIN = 0;
    private const NET_MAX = 300;

    /**
     * Checks and normalises a name (R6.2, R7.2).
     *
     * Leading/trailing whitespace is removed; afterwards the length must be
     * between 1 and 100 characters. A pseudonym is allowed, since only the
     * length is checked (R25.3).
     *
     * @throws ValidationException if the name is empty or longer than 100
     *         characters.
     */
    public function checkName(string $name, string $field = 'field.name'): string
    {
        $name   = trim($name);
        $length = mb_strlen($name);

        if ($length < self::NAME_MIN || $length > self::NAME_MAX) {
            throw ValidationException::forField(
                $field,
                'validation.length',
                ['min' => self::NAME_MIN, 'max' => self::NAME_MAX]
            );
        }

        return $name;
    }

    /**
     * Checks a gender for one of the allowed values and returns the normalised
     * value (R6.3).
     *
     * @return 'male'|'female'
     *
     * @throws ValidationException if the value is neither "männlich" nor
     *         "weiblich".
     */
    public function checkGender(string $gender, string $field = 'field.gender'): string
    {
        $value = strtolower(trim($gender));

        if ($value === self::GENDER_MALE || $value === self::GENDER_FEMALE) {
            return $value;
        }

        throw ValidationException::forField(
            $field,
            'validation.gender'
        );
    }

    /**
     * Checks a net result as integer in the range 0–300 (R22.4).
     *
     * @throws ValidationException if the value is outside 0–300.
     */
    public function checkNet(int $net, string $field = 'field.net'): int
    {
        if ($net < self::NET_MIN || $net > self::NET_MAX) {
            throw ValidationException::forField(
                $field,
                'validation.integer_range',
                ['min' => self::NET_MIN, 'max' => self::NET_MAX]
            );
        }

        return $net;
    }

    /**
     * Checks an integer value for the range 0–300 (R12.2/R12.3).
     *
     * Used for target score and maximum handicap of the season configuration;
     * the field is named for the error message.
     *
     * @throws ValidationException if the value is outside 0–300.
     */
    /**
     * Checks an integer against an inclusive range.
     *
     * @throws ValidationException if the value lies outside `[$min, $max]`.
     */
    public function checkRange(int $value, int $min, int $max, string $field): int
    {
        if ($value < $min || $value > $max) {
            throw ValidationException::forField(
                $field,
                'validation.integer_range',
                ['min' => $min, 'max' => $max]
            );
        }

        return $value;
    }

    public function checkRange0To300(int $value, string $field): int
    {
        if ($value < self::NET_MIN || $value > self::NET_MAX) {
            throw ValidationException::forField(
                $field,
                'validation.integer_range',
                ['min' => self::NET_MIN, 'max' => self::NET_MAX]
            );
        }

        return $value;
    }

    /**
     * Checks a lane count as even integer of at least 2 (R8.2, R8.11), so that
     * the lanes can be split completely into lane pairs.
     *
     * @throws ValidationException if the lane count is less than 2 or odd.
     */
    public function checkLaneCount(int $laneCount, string $field = 'field.lane_count'): int
    {
        if ($laneCount < 2 || $laneCount % 2 !== 0) {
            throw ValidationException::forField(
                $field,
                'validation.lane_count'
            );
        }

        return $laneCount;
    }

    /**
     * Checks an amount of money for a value ≥ 0.00 (R8.2).
     *
     * The amount is normalised to two decimals (to the cent); negative or
     * non-finite values are not allowed.
     *
     * @throws ValidationException if the amount is less than 0 or not finite.
     */
    public function checkMoneyAmount(float $amount, string $field = 'field.amount'): float
    {
        if (! is_finite($amount) || $amount < 0.0) {
            throw ValidationException::forField(
                $field,
                'validation.amount'
            );
        }

        return round($amount, 2);
    }

    /**
     * Checks a player's manually entered previous season record (R29.3–R29.7).
     *
     * Rules:
     * - The number of games played must be an integer ≥ 0 (R29.3/R29.4).
     * - The net pins total must be an integer ≥ 0 (R29.5/R29.6).
     * - If the number is > 0, the derivable average (net pins total ÷ number
     *   of games) must be in the range 0.00–300.00 (R29.7). With number = 0 the
     *   plausibility check is skipped, since no average can be derived
     *   (consistent with R29.10).
     *
     * Integers are already ensured by the `int` signature; the range and
     * plausibility conditions are checked.
     *
     * @throws ValidationException if the number or the sum is negative
     *         (field-related) or the derivable average is outside 0–300.
     */
    public function checkPreviousSeasonRecord(int $numberOfGames, int $netPinsTotal): void
    {
        if ($numberOfGames < 0) {
            throw ValidationException::forField(
                'field.previous_games',
                'validation.previous_games'
            );
        }

        if ($netPinsTotal < 0) {
            throw ValidationException::forField(
                'field.previous_pins',
                'validation.previous_pins'
            );
        }

        if ($numberOfGames > 0) {
            $average = $netPinsTotal / $numberOfGames;
            if ($average < self::NET_MIN || $average > self::NET_MAX) {
                throw ValidationException::forField(
                    'field.previous_record',
                    'validation.previous_average',
                    ['average' => sprintf('%.2f', $average), 'min' => self::NET_MIN, 'max' => self::NET_MAX]
                );
            }
        }
    }

    /**
     * Checks that home and away team of a fixture differ (R8.6).
     *
     * This rule is enforced in the application layer, since it cannot be
     * expressed as a CHECK constraint on the table `fixture` in MariaDB
     * (the columns involved are in foreign keys with a referential action;
     * MariaDB otherwise reports error 1901). Fixtures not fixed yet are
     * allowed: if at least one of the two team references is NULL, no equality
     * check takes place (R18.7).
     *
     * @throws ValidationException if both team references are set and
     *         identical.
     */
    public function checkDifferentTeams(
        ?int $teamSeasonHomeId,
        ?int $teamSeasonAwayId,
        string $field = 'field.fixture'
    ): void {
        if ($teamSeasonHomeId !== null
            && $teamSeasonAwayId !== null
            && $teamSeasonHomeId === $teamSeasonAwayId
        ) {
            throw ValidationException::forField(
                $field,
                'validation.different_teams'
            );
        }
    }

    /**
     * Checks and normalises a CMS text for a maximum length (R33.11, R34.8).
     *
     * Only checks the length; sanitizing the content (XSS protection) happens
     * separately via the {@see HtmlSanitizer}.
     *
     * @param int $maxLength Allowed maximum length in characters (> 0).
     *
     * @throws ValidationException if the text exceeds the maximum length.
     */
    public function checkCmsText(string $text, int $maxLength, string $field = 'field.text'): string
    {
        if (mb_strlen($text) > $maxLength) {
            throw ValidationException::forField(
                $field,
                'validation.max_length',
                ['max' => $maxLength]
            );
        }

        return $text;
    }

    /**
     * Checks a CMS text for a minimum and a maximum length (R33.5, R33.6).
     *
     * Used for required fields with a lower limit – such as the title (1–200
     * characters) and the text content (1–5000 characters) of a home page
     * post. The length is checked after trimming leading/trailing whitespace,
     * so that a value consisting only of whitespace counts as empty; the
     * trimmed value is returned. Sanitizing the content (XSS protection)
     * happens separately via the {@see HtmlSanitizer}.
     *
     * @param int $minLength Minimum length in characters (>= 1).
     * @param int $maxLength Maximum length in characters.
     *
     * @throws ValidationException if the trimmed text is shorter than the
     *         minimum or longer than the maximum length.
     */
    public function checkCmsTextWithMin(string $text, int $minLength, int $maxLength, string $field = 'field.text'): string
    {
        $trimmed = trim($text);
        $length = mb_strlen($trimmed);

        if ($length < $minLength || $length > $maxLength) {
            throw ValidationException::forField(
                $field,
                'validation.length',
                ['min' => $minLength, 'max' => $maxLength]
            );
        }

        return $trimmed;
    }
}
