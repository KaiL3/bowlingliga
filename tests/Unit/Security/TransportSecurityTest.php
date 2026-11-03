<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\TransportSecurity;
use PHPUnit\Framework\TestCase;

/**
 * HTTPS detection and the HSTS header: only over HTTPS, without
 * `includeSubDomains`.
 */
final class TransportSecurityTest extends TestCase
{
    public function testHttpsIsDetectedFromTheWebServerOrPort443(): void
    {
        self::assertTrue(TransportSecurity::isHttps(['HTTPS' => 'on']));
        self::assertTrue(TransportSecurity::isHttps(['HTTPS' => '1']));
        self::assertTrue(TransportSecurity::isHttps(['SERVER_PORT' => '443']));

        self::assertFalse(TransportSecurity::isHttps([]));
        self::assertFalse(TransportSecurity::isHttps(['HTTPS' => '']));
        self::assertFalse(TransportSecurity::isHttps(['HTTPS' => 'off']));
        self::assertFalse(TransportSecurity::isHttps(['HTTPS' => 'OFF']));
        self::assertFalse(TransportSecurity::isHttps(['SERVER_PORT' => '80']));
    }

    public function testHstsHeaderOnlyOverHttps(): void
    {
        self::assertSame('Strict-Transport-Security: max-age=86400', TransportSecurity::strictTransportSecurityHeader(['HTTPS' => 'on']));
        self::assertNull(TransportSecurity::strictTransportSecurityHeader(['SERVER_PORT' => '80']));
    }

    public function testHstsHeaderLeavesSubdomainsAlone(): void
    {
        self::assertStringNotContainsString('includeSubDomains', (string) TransportSecurity::strictTransportSecurityHeader(['HTTPS' => 'on']));
    }
}
