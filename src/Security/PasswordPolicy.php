<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Password policy of one kind of account (R23.15): minimum strength in bits,
 * maximum age in days (-1 = unlimited) and the number of earlier passwords
 * that may not be used again.
 */
final readonly class PasswordPolicy
{
    /** Value of the maximum age that means "never expires". */
    public const UNLIMITED = -1;

    public const MAX_BITS = 200;
    public const MAX_AGE_DAYS = 3650;
    public const MAX_HISTORY = 24;

    /**
     * @throws ValidationException for a value outside its range.
     */
    public function __construct(
        public int $minBits,
        public int $maxAgeDays,
        public int $historySize,
    ) {
        if ($minBits < 0 || $minBits > self::MAX_BITS) {
            throw ValidationException::forField('field.min_bits', 'validation.integer_range', ['min' => 0, 'max' => self::MAX_BITS]);
        }
        if ($maxAgeDays !== self::UNLIMITED && ($maxAgeDays < 1 || $maxAgeDays > self::MAX_AGE_DAYS)) {
            throw ValidationException::forField('field.max_age_days', 'validation.password_age', ['max' => self::MAX_AGE_DAYS]);
        }
        if ($historySize < 0 || $historySize > self::MAX_HISTORY) {
            throw ValidationException::forField('field.history_size', 'validation.integer_range', ['min' => 0, 'max' => self::MAX_HISTORY]);
        }
    }

    /**
     * Policy of a new installation (R23.15): admins need a stronger password
     * than players; passwords do not expire; the last five may not be reused.
     */
    public static function defaultFor(AccountKind $kind): self
    {
        return match ($kind) {
            AccountKind::Admin => new self(70, self::UNLIMITED, 5),
            AccountKind::User  => new self(50, self::UNLIMITED, 5),
        };
    }

    /**
     * Whether a password changed at $changedAt is too old at $now (R23.17).
     */
    public function isExpired(\DateTimeImmutable $changedAt, \DateTimeImmutable $now): bool
    {
        if ($this->maxAgeDays === self::UNLIMITED) {
            return false;
        }

        return $changedAt->modify('+' . $this->maxAgeDays . ' days') <= $now;
    }
}
