<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\CsrfTokenException;
use Bowlingliga\Security\CsrfTokenHelper;
use Bowlingliga\Security\SessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the CSRF token helper following the synchronizer token
 * pattern (task 19, R22.5).
 *
 * Checked are generation, stability and session-bound validation of the token
 * as well as the rejection of missing, wrong and session-less tokens.
 */
final class CsrfTokenHelperTest extends TestCase
{
    private CsrfTokenTestSession $session;
    private CsrfTokenHelper $helper;

    protected function setUp(): void
    {
        $this->session = new CsrfTokenTestSession();
        $this->helper  = new CsrfTokenHelper($this->session);
    }

    /**
     * R22.5: on the first fetch a non-empty token is generated and stored bound
     * to the session.
     */
    public function testFetchTokenGeneratesAndStoresToken(): void
    {
        $token = $this->helper->fetchToken();

        self::assertNotSame('', $token);
        self::assertSame(
            $token,
            $this->session->retrieve(CsrfTokenHelper::SESSION_KEY)
        );
    }

    /**
     * R22.5: a token once generated stays stable for the duration of the
     * session, so that several open forms use the same valid token.
     */
    public function testFetchTokenIsStableWithinTheSession(): void
    {
        $firstItem  = $this->helper->fetchToken();
        $secondItem = $this->helper->fetchToken();

        self::assertSame($firstItem, $secondItem);
    }

    /**
     * R22.5: the generated token is long and random enough (64 hex characters
     * from 32 bytes).
     */
    public function testTokenIsLongEnoughAndHexadecimal(): void
    {
        $token = $this->helper->fetchToken();

        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    /**
     * R22.5: Zwei getrennte Sitzungen erhalten unterschiedliche Tokens.
     */
    public function testDifferentSessionsGetDifferentTokens(): void
    {
        $tokenA = $this->helper->fetchToken();

        $secondSession = new CsrfTokenTestSession();
        $tokenB = (new CsrfTokenHelper($secondSession))->fetchToken();

        self::assertNotSame($tokenA, $tokenB);
    }

    /**
     * R22.5: the correct, session-bound token is accepted.
     */
    public function testCheckAcceptsCorrectToken(): void
    {
        $token = $this->helper->fetchToken();

        $this->helper->check($token);

        // No error => expectation met.
        self::assertTrue($this->helper->isValid($token));
    }

    /**
     * R22.5: a wrong token is rejected.
     */
    public function testCheckRejectsWrongToken(): void
    {
        $this->helper->fetchToken();

        $this->expectException(CsrfTokenException::class);

        $this->helper->check('falsches-token');
    }

    /**
     * R22.5: a missing (null) token is rejected.
     */
    public function testCheckRejectsMissingToken(): void
    {
        $this->helper->fetchToken();

        $this->expectException(CsrfTokenException::class);

        $this->helper->check(null);
    }

    /**
     * R22.5: an empty token is rejected.
     */
    public function testCheckRejectsEmptyToken(): void
    {
        $this->helper->fetchToken();

        $this->expectException(CsrfTokenException::class);

        $this->helper->check('');
    }

    /**
     * R22.5: manipulated form data (array instead of string, e.g.
     * `csrf_token[]=…`) is rejected without triggering a TypeError.
     */
    public function testIsValidRejectsArray(): void
    {
        $token = $this->helper->fetchToken();

        self::assertFalse($this->helper->isValid([$token]));
        self::assertFalse($this->helper->isValid(['x' => $token]));
    }

    /**
     * R22.5: without a token stored in the session no check is valid, even if
     * an arbitrary value is passed.
     */
    public function testCheckWithoutSessionTokenFails(): void
    {
        self::assertFalse($this->helper->isValid('irgendein-wert'));

        $this->expectException(CsrfTokenException::class);
        $this->helper->check('irgendein-wert');
    }

    /**
     * R22.5: the generated hidden field contains the field name and the current
     * token and escapes the value context-aware.
     */
    public function testFieldHtmlContainsFieldNameAndToken(): void
    {
        $token = $this->helper->fetchToken();
        $html  = $this->helper->fieldHtml();

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('name="' . CsrfTokenHelper::FIELD_NAME . '"', $html);
        self::assertStringContainsString('value="' . $token . '"', $html);
    }
}

/**
 * Slim in-memory session store for the CSRF test (without a real PHP
 * session). Keeps the values in an array.
 */
final class CsrfTokenTestSession implements SessionStore
{
    /** @var array<string, int|string> */
    private array $values = [];

    public function set(string $key, int|string $value): void
    {
        $this->values[$key] = $value;
    }

    public function retrieve(string $key): int|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function regenerateId(): void
    {
        // Irrelevant for the CSRF test.
    }

    public function end(): void
    {
        $this->values = [];
    }
}
