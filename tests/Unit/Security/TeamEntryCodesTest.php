<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\TeamEntryCodes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Codes of the result entry by the teams (R37.2). */
final class TeamEntryCodesTest extends TestCase
{
    public function testCodeCarriesFixtureSideAndVersion(): void
    {
        $codes = new TeamEntryCodes('schluessel');
        $code = $codes->create(4711, 'away', 3);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{26}$/', $code, 'URL-safe, fits into a small QR code.');
        self::assertSame(['fixture_id' => 4711, 'side' => 'away', 'version' => 3], $codes->read($code));
        self::assertSame($code, $codes->create(4711, 'away', 3), 'The same code can be printed again.');
        self::assertNotSame($code, $codes->create(4711, 'home', 3));
    }

    public function testAlteredForeignOrMalformedCodesAreNotAccepted(): void
    {
        $codes = new TeamEntryCodes('schluessel');
        $code = $codes->create(12, 'home', 1);

        // Changing the fixture in the payload breaks the MAC.
        $other = (new TeamEntryCodes('schluessel'))->create(13, 'home', 1);
        $mixed = substr($other, 0, 9) . substr($code, 9);

        self::assertNull($codes->read($mixed));
        self::assertNull((new TeamEntryCodes('anderer Schlüssel'))->read($code), 'Codes of another installation are invalid.');
        self::assertNull($codes->read(''));
        self::assertNull($codes->read('../../etc/passwd'));
        self::assertNull($codes->read(str_repeat('A', 26)));
    }

    public function testWithoutKeyNoCodesExist(): void
    {
        $codes = new TeamEntryCodes('');
        self::assertFalse($codes->isAvailable());
        self::assertNull($codes->read('AAAAAAAAAAAAAAAAAAAAAAAAAA'));

        $this->expectException(RuntimeException::class);
        $codes->create(1, 'home', 1);
    }
}
