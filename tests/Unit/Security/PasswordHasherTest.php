<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the password hashing of the admin accounts (task 20.1).
 *
 * Validates: R23.3
 *
 * R23.3: passwords are only stored as salt+hash, never in
 * plaintext; verification happens against the stored hash.
 */
final class PasswordHasherTest extends TestCase
{
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasher();
    }

    /**
     * R23.3: the generated hash differs from the plaintext and is a
     * non-empty string (no plaintext stored).
     */
    public function testHashDiffersFromPlaintext(): void
    {
        $hash = $this->hasher->hash('geheim-Passwort-123');

        self::assertNotSame('geheim-Passwort-123', $hash);
        self::assertNotSame('', $hash);
    }

    /**
     * R23.3: two hashes of the same password differ (automatic,
     * random salt).
     */
    public function testHashesAreSaltedAndDifferent(): void
    {
        $firstHash  = $this->hasher->hash('gleiches-Passwort');
        $secondHash = $this->hasher->hash('gleiches-Passwort');

        self::assertNotSame($firstHash, $secondHash);
    }

    /**
     * R23.3: the correct password is verified against its hash.
     */
    public function testCorrectPasswordIsVerified(): void
    {
        $hash = $this->hasher->hash('richtiges-Passwort');

        self::assertTrue($this->hasher->check('richtiges-Passwort', $hash));
    }

    /**
     * R23.3: a wrong password is rejected against the hash.
     */
    public function testWrongPasswordIsRejected(): void
    {
        $hash = $this->hasher->hash('richtiges-Passwort');

        self::assertFalse($this->hasher->check('falsches-Passwort', $hash));
    }

    /**
     * R23.3: a freshly generated hash needs no rehash.
     */
    public function testFreshHashDoesNotNeedRehash(): void
    {
        $hash = $this->hasher->hash('ein-Passwort');

        self::assertFalse($this->hasher->needsRehash($hash));
    }
}
