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
 * Validates: R38.2, R38.3, R5.1
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
        self::assertSame('Bowlingliga Musterstadt', $this->settings->rename('  Bowlingliga Musterstadt '));

        self::assertSame('Bowlingliga Musterstadt', $this->store->name);
        self::assertSame('Bowlingliga Musterstadt', $this->settings->name());
    }

    public function testNameMustHaveOneToHundredCharacters(): void
    {
        $this->settings->rename(str_repeat('x', 100));

        foreach (['   ', str_repeat('x', 101)] as $invalid) {
            try {
                $this->settings->rename($invalid);
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
            $this->settings->rename('Andere Liga');
        } finally {
            self::assertNull($this->store->name);
        }
    }
}
