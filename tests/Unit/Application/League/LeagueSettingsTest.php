<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\League;

use Bowlingliga\Application\League\LeagueSettings;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for {@see LeagueSettings}.
 *
 * Validates: R38.2, R38.3, R40.1, R5.1
 */
final class LeagueSettingsTest extends TestCase
{
    private InMemoryLeagueSettingsStore $store;
    private SwitchableWriteAccess $access;
    private LeagueSettings $settings;

    protected function setUp(): void
    {
        $this->store    = new InMemoryLeagueSettingsStore();
        $this->access   = new SwitchableWriteAccess(true);
        $this->settings = new LeagueSettings($this->store, new Validator(), $this->access);
    }

    public function testWithoutAStoredNameTheDefaultApplies(): void
    {
        self::assertSame('Bowlingliga', $this->settings->name());
        self::assertSame(LeagueSettings::DEFAULT_NAME, LeagueSettings::nameFrom($this->store));
    }

    public function testRenameStoresTheTrimmedName(): void
    {
        self::assertSame('Bowlingliga Musterstadt', $this->settings->save('  Bowlingliga Musterstadt ', false));

        self::assertSame('Bowlingliga Musterstadt', $this->store->name);
        self::assertSame('Bowlingliga Musterstadt', $this->settings->name());
    }

    public function testPlayerDataIsNotPublicUntilSwitchedOn(): void
    {
        self::assertFalse($this->settings->playersPublic());

        $this->settings->save('Bowlingliga', true);
        self::assertTrue($this->settings->playersPublic());

        $this->settings->save('Bowlingliga', false);
        self::assertFalse($this->settings->playersPublic());
    }

    public function testNameMustHaveOneToHundredCharacters(): void
    {
        $this->settings->save(str_repeat('x', 100), false);

        foreach (['   ', str_repeat('x', 101)] as $invalid) {
            try {
                $this->settings->save($invalid, true);
                self::fail('Invalid name accepted: "' . $invalid . '"');
            } catch (ValidationException) {
                self::assertSame(1, $this->store->saves, 'Nothing is saved.');
            }
        }
    }

    public function testRenameRequiresWriteAccess(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        try {
            $this->settings->save('Andere Liga', true);
        } finally {
            self::assertNull($this->store->name);
        }
    }
}
