<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Trusted devices of an account (R5.10).
 *
 * After a complete login (password and, if active, second factor) the
 * browser becomes a trusted device of the account for 90 days; every further
 * login renews this. The account lock after failed attempts (R5.6) does not
 * apply to a trusted device: whoever knows a username can lock the account
 * for unknown devices, but not keep its owner out. A trusted device counts
 * its own failed attempts instead; after five of them it is forgotten and
 * the account lock applies to it as well.
 *
 * A device is only valid for the session version of the account it was
 * trusted with, so changing the password or the two-factor login and
 * "log out all other sessions" also forget all other devices (R5.9).
 *
 * Nothing in here is specific to admin accounts: the rule is meant for every
 * kind of account.
 */
final class TrustedDevices
{
    /** Validity of a trusted device: 90 days from the last complete login. */
    public const LIFETIME_SECONDS = 90 * 24 * 60 * 60;

    /** Failed attempts after which a trusted device is forgotten. */
    public const MAX_FAILED_ATTEMPTS = 5;

    /** At most this many trusted devices per account, the most recently used ones. */
    public const MAX_DEVICES_PER_ACCOUNT = 10;

    public function __construct(
        private readonly TrustedDeviceStore $store,
        private readonly DeviceCookie $cookie,
    ) {
    }

    /** Whether a cookie value has the form of a token (43 characters base64url). */
    public static function isWellFormedToken(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1;
    }

    /**
     * The trusted device of this browser for the account, or null if the
     * browser is not trusted (no cookie, unknown, expired or of an earlier
     * session version).
     *
     * @return array{id: int, session_version: int, failed_attempts: int, expires_at: string}|null
     */
    public function recognise(int $accountId, int $sessionVersion, \DateTimeImmutable $now): ?array
    {
        $token = $this->cookie->token();
        if ($token === null) {
            return null;
        }

        $device = $this->store->find(self::hash($token), $accountId);
        if ($device === null || $device['session_version'] !== $sessionVersion) {
            return null;
        }

        $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $device['expires_at']);

        return $expiresAt !== false && $now < $expiresAt ? $device : null;
    }

    /**
     * Trusts this browser for the account after a complete login, or renews
     * it. The browser keeps its token, so it can stand for several accounts.
     */
    public function trust(int $accountId, int $sessionVersion, \DateTimeImmutable $now): void
    {
        $token = $this->cookie->token() ?? self::newToken();

        $this->store->save(
            self::hash($token),
            $accountId,
            $sessionVersion,
            $now,
            $now->add(new \DateInterval('PT' . self::LIFETIME_SECONDS . 'S')),
        );
        $this->store->prune($accountId, $sessionVersion, $now, self::MAX_DEVICES_PER_ACCOUNT);
        $this->cookie->issue($token, self::LIFETIME_SECONDS);
    }

    /**
     * Counts a failed attempt from a trusted device; after the fifth one the
     * device is forgotten.
     *
     * @param array{id: int, session_version: int, failed_attempts: int, expires_at: string} $device
     */
    public function recordFailure(array $device): void
    {
        $failedAttempts = $device['failed_attempts'] + 1;

        if ($failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->store->delete($device['id']);

            return;
        }

        $this->store->updateFailedAttempts($device['id'], $failedAttempts);
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
