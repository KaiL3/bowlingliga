<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\TotpSecretEncryption;
use Bowlingliga\Security\TotpSecretEncryptionException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the encryption of the TOTP secret (task 20.1).
 *
 * Validates: R23.9
 *
 * R23.9: the TOTP secret is stored encrypted; the key lives
 * outside the database. The ciphertext differs from the plaintext and can only
 * be decrypted again with the correct key.
 *
 * If the libsodium extension is not available at runtime, the tests are
 * skipped instead of aborting with a fatal error.
 */
final class TotpSecretEncryptionTest extends TestCase
{
    private const PLAINTEXT_SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        if (! TotpSecretEncryption::isAvailable()) {
            self::markTestSkipped(
                'The libsodium extension is not installed; the '
                . 'TOTP secret encryption cannot be tested.'
            );
        }
    }

    /**
     * R23.9: an encrypted secret can be decrypted again with the
     * same key to the original plaintext (roundtrip).
     */
    public function testRoundtripReturnsOriginalSecret(): void
    {
        $encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());

        $ciphertext  = $encryption->encrypt(self::PLAINTEXT_SECRET);
        $decrypted = $encryption->decrypt($ciphertext);

        self::assertSame(self::PLAINTEXT_SECRET, $decrypted);
    }

    /**
     * R23.9: the ciphertext does not contain the plaintext.
     */
    public function testCiphertextDoesNotContainThePlaintext(): void
    {
        $encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());

        $ciphertext = $encryption->encrypt(self::PLAINTEXT_SECRET);

        self::assertStringNotContainsString(self::PLAINTEXT_SECRET, $ciphertext);
        self::assertNotSame(self::PLAINTEXT_SECRET, $ciphertext);
    }

    /**
     * R23.9: two encryptions of the same secret give different
     * ciphertexts (random nonce per operation).
     */
    public function testDifferentCiphertextsBecauseOfNonce(): void
    {
        $encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());

        $firstCipher  = $encryption->encrypt(self::PLAINTEXT_SECRET);
        $secondCipher  = $encryption->encrypt(self::PLAINTEXT_SECRET);

        self::assertNotSame($firstCipher, $secondCipher);
    }

    /**
     * R23.9: the ciphertext cannot be decrypted with a wrong key
     * (authenticated encryption).
     */
    public function testWrongKeyFails(): void
    {
        $original = new TotpSecretEncryption(TotpSecretEncryption::generateKey());
        $foreign    = new TotpSecretEncryption(TotpSecretEncryption::generateKey());

        $ciphertext = $original->encrypt(self::PLAINTEXT_SECRET);

        $this->expectException(TotpSecretEncryptionException::class);
        $foreign->decrypt($ciphertext);
    }

    /**
     * R23.9: a tampered ciphertext is detected and rejected on
     * decryption.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        $encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());

        $ciphertext = $encryption->encrypt(self::PLAINTEXT_SECRET);
        // Letztes Byte kippen (Manipulation).
        $ciphertext[strlen($ciphertext) - 1] = ($ciphertext[strlen($ciphertext) - 1] ^ "\x01");

        $this->expectException(TotpSecretEncryptionException::class);
        $encryption->decrypt($ciphertext);
    }

    /**
     * R23.9: a key with an invalid length is rejected.
     */
    public function testInvalidKeyLengthIsRejected(): void
    {
        $this->expectException(TotpSecretEncryptionException::class);
        new TotpSecretEncryption('zu-kurz');
    }
}
