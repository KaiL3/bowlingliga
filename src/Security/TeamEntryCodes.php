<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use RuntimeException;

/**
 * Codes with which a team enters results for its fixture (R37.2).
 *
 * A code names fixture, side and code version and carries a truncated
 * HMAC-SHA-256 over them (96 bits), so it can neither be guessed nor altered
 * to another fixture. Nothing secret is stored: the same code can be printed
 * again, and the admin invalidates printed codes by raising the version.
 * The key is derived from the TOTP key of the configuration.
 *
 * Format: base64url of fixture id (4 bytes), side (1 byte), version
 * (2 bytes) and MAC (12 bytes) – 26 characters.
 */
final class TeamEntryCodes
{
    private const MAC_BYTES = 12;
    private const SIDES = ['home' => 1, 'away' => 2];

    private readonly string $key;

    public function __construct(string $installationKey)
    {
        $this->key = $installationKey === '' ? '' : hash_hmac('sha256', 'team-entry', $installationKey, true);
    }

    public function isAvailable(): bool
    {
        return $this->key !== '';
    }

    public function create(int $fixtureId, string $side, int $version): string
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('The key for the team codes (totp_key) is missing in the configuration.');
        }
        if (! isset(self::SIDES[$side]) || $fixtureId < 1 || $version < 1 || $version > 0xFFFF) {
            throw new RuntimeException('Invalid data for a team code.');
        }

        $payload = pack('NCn', $fixtureId, self::SIDES[$side], $version);

        return self::encode($payload . $this->mac($payload));
    }

    /**
     * @return array{fixture_id: int, side: string, version: int}|null null for an unknown or altered code
     */
    public function read(string $code): ?array
    {
        if (! $this->isAvailable() || preg_match('/^[A-Za-z0-9_-]{26}$/', $code) !== 1) {
            return null;
        }

        $raw = base64_decode(strtr($code, '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 7 + self::MAC_BYTES) {
            return null;
        }
        // The decoder ignores the unused low bits of the last character, so
        // several spellings yield the same bytes; only the issued one counts.
        if (self::encode($raw) !== $code) {
            return null;
        }

        $payload = substr($raw, 0, 7);
        if (! hash_equals($this->mac($payload), substr($raw, 7))) {
            return null;
        }

        /** @var array{fixture: int, side: int, version: int} $values */
        $values = unpack('Nfixture/Cside/nversion', $payload);
        $side = array_search($values['side'], self::SIDES, true);

        return $side === false ? null : ['fixture_id' => $values['fixture'], 'side' => $side, 'version' => $values['version']];
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function mac(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $this->key, true), 0, self::MAC_BYTES);
    }
}
