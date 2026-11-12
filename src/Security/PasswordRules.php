<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Persistence\AdminAccountRepository;

/**
 * The password policies applied (R23.15–R23.18).
 *
 * - A new password needs the minimum length and the minimum strength of the
 *   policy of its kind of account, and it may be neither the current one nor
 *   one of the last passwords kept by the history.
 * - Every password set is stored with its strength and the time of the
 *   change; its hash goes into the history.
 * - At the login, where the plaintext is at hand once more, a password that
 *   has expired or no longer meets the strength of the policy, or that was
 *   set by somebody else, has to be replaced first.
 */
final class PasswordRules
{
    /** Shortest password regardless of its strength. */
    public const MIN_LENGTH = 8;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /**
     * @param list<string> $commonWords Words every account of this league
     *        shares (league name), counted as known to an attacker.
     * @param callable(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly PasswordPolicyStore $policies,
        private readonly PasswordHistoryStore $history,
        private readonly AdminAccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly PasswordStrength $strength = new PasswordStrength(),
        private readonly array $commonWords = [],
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function policyFor(AccountKind $kind): PasswordPolicy
    {
        return $this->policies->policyFor($kind);
    }

    /**
     * Strength of a password for the account named (R23.16).
     */
    public function bits(string $password, string $username = ''): int
    {
        return $this->strength->bits($password, [$username, ...$this->commonWords]);
    }

    /**
     * Checks a new password against the policy (R23.15, R23.18) and returns
     * its strength.
     *
     * @param int|null $accountId Existing account (current password and
     *        history are checked), null for a new one.
     *
     * @throws AccountException for a too short, too weak or reused password.
     */
    public function check(AccountKind $kind, string $password, string $username, ?int $accountId = null): int
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw AccountException::passwordTooShort(self::MIN_LENGTH);
        }

        $policy = $this->policyFor($kind);
        $bits   = $this->bits($password, $username);
        if ($bits < $policy->minBits) {
            throw AccountException::passwordTooWeak($bits, $policy->minBits);
        }

        if ($accountId !== null) {
            $current = $this->accounts->find($accountId)['password_hash'] ?? null;
            $earlier = $policy->historySize > 0 ? $this->history->recent($accountId, $policy->historySize) : [];
            foreach (array_filter([$current, ...$earlier], is_string(...)) as $hash) {
                if ($this->hasher->check($password, $hash)) {
                    throw AccountException::passwordReused($policy->historySize);
                }
            }
        }

        return $bits;
    }

    /**
     * Sets a new password for an existing account after checking it (R23.15,
     * R23.18); all its sessions end (the session version rises).
     *
     * @param bool $mustChange The password was chosen by somebody else; the
     *        owner has to replace it at the next login (R23.17).
     *
     * @throws AccountException see {@see check()}.
     */
    public function change(AccountKind $kind, int $accountId, string $username, string $password, bool $mustChange = false): void
    {
        $bits = $this->check($kind, $password, $username, $accountId);
        $hash = $this->hasher->hash($password);

        $this->accounts->updatePasswordHash($accountId, $hash);
        $this->remember($accountId, $hash, $bits, $mustChange);
    }

    /**
     * Records a password just stored: strength, time of the change and the
     * history entry (R23.17, R23.18).
     */
    public function remember(int $accountId, string $hash, int $bits, bool $mustChange = false): void
    {
        $now = ($this->clock)();
        $this->accounts->recordPasswordChange($accountId, $bits, $now, $mustChange);
        $this->history->add($accountId, $hash, $now, PasswordPolicy::MAX_HISTORY);
    }

    /**
     * Whether the account has to choose a new password before the login
     * completes, checked with the plaintext just verified (R23.17).
     *
     * @param array<string, mixed> $account Row of the account.
     */
    public function changeReason(AccountKind $kind, array $account, string $plaintext): ?PasswordChangeReason
    {
        if ((bool) ($account['must_change_password'] ?? false)) {
            return PasswordChangeReason::Required;
        }

        $policy    = $this->policyFor($kind);
        $changedAt = $account['password_changed_at'] ?? null;
        if (is_string($changedAt) && $changedAt !== '') {
            $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $changedAt);
            if ($at !== false && $policy->isExpired($at, ($this->clock)())) {
                return PasswordChangeReason::Expired;
            }
        }

        if ($this->bits($plaintext, (string) ($account['username'] ?? '')) < $policy->minBits) {
            return PasswordChangeReason::TooWeak;
        }

        return null;
    }
}
