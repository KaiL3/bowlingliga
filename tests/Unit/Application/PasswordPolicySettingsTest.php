<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\PasswordPolicySettings;
use Bowlingliga\Presentation\SystemSettingsController;
use Bowlingliga\Presentation\SystemSettingsView;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\AccountPermissions;
use Bowlingliga\Security\PermissionDeniedException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Tests\Unit\Security\FixedLoggedInAccount;
use Bowlingliga\Tests\Unit\Security\InMemoryPasswordPolicyStore;
use Bowlingliga\Tests\Unit\Security\InMemoryPermissionStore;
use PHPUnit\Framework\TestCase;

/**
 * Password policies in the system area (R23.10, R23.15): only a system
 * admin reads and changes them; invalid values change nothing.
 */
final class PasswordPolicySettingsTest extends TestCase
{
    private InMemoryPasswordPolicyStore $store;
    private InMemoryPermissionStore $permissions;
    private SwitchableWriteAccess $access;
    private PasswordPolicySettings $settings;

    protected function setUp(): void
    {
        $this->store       = new InMemoryPasswordPolicyStore();
        $this->permissions = new InMemoryPermissionStore();
        $this->permissions->replace(1, ['system.admin']);
        $this->access   = new SwitchableWriteAccess(true);
        $this->settings = new PasswordPolicySettings($this->store, $this->access, new AccountPermissions($this->permissions, new FixedLoggedInAccount(1)));
    }

    public function testASystemAdminSavesThePolicyOfAKind(): void
    {
        $kind = $this->settings->save('user', ' 60 ', '180', '3');

        self::assertSame(AccountKind::User, $kind);
        $saved = $this->settings->policies()['user'];
        self::assertSame([60, 180, 3], [$saved->minBits, $saved->maxAgeDays, $saved->historySize]);
        self::assertSame(70, $this->settings->policies()['admin']->minBits, 'The other kind stays.');
    }

    public function testMinusOneMeansUnlimited(): void
    {
        $this->settings->save('admin', '80', '-1', '0');

        self::assertSame(-1, $this->store->policyFor(AccountKind::Admin)->maxAgeDays);
    }

    public function testInvalidValuesSaveNothing(): void
    {
        foreach ([['admin', 'viel', '-1', '5'], ['admin', '70', '0', '5'], ['admin', '70', '-1', '30'], ['gast', '70', '-1', '5']] as $input) {
            try {
                $this->settings->save(...$input);
                self::fail('Rejected: ' . implode(', ', $input));
            } catch (ValidationException) {
                self::assertSame([], $this->store->policies);
            }
        }
    }

    public function testALeagueAdminMayNeitherReadNorChangeThem(): void
    {
        $this->permissions->replace(1, []);

        try {
            $this->settings->policies();
            self::fail('Reading needs system admin.');
        } catch (PermissionDeniedException) {
        }

        $this->expectException(PermissionDeniedException::class);
        $this->settings->save('admin', '10', '-1', '0');
    }

    public function testWithoutLoginNothingHappens(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->settings->save('admin', '10', '-1', '0');
    }

    public function testThePageShowsBothPoliciesAndConfirmsTheSave(): void
    {
        $controller = new SystemSettingsController($this->settings, new SystemSettingsView());

        $result = $controller->savePolicy(['account_kind' => 'user', 'min_bits' => '55', 'max_age_days' => '-1', 'history_size' => '4']);
        self::assertTrue($result->successful);
        self::assertSame('/admin/system?saved=user', $result->redirect);

        $html = $controller->page('<input name="csrf_token">', 'user');
        self::assertStringContainsString('Die Passwortrichtlinie für Spieler ist gespeichert.', $html);
        self::assertStringContainsString('Passwortrichtlinie: Admins', $html);
        self::assertStringContainsString('id="policy-user-bits" name="min_bits" value="55"', $html);
        self::assertStringContainsString('id="policy-admin-bits" name="min_bits" value="70"', $html);

        $error = $controller->savePolicy(['account_kind' => 'admin', 'min_bits' => '500', 'max_age_days' => '-1', 'history_size' => '4']);
        self::assertFalse($error->successful);
        self::assertSame('Das Feld Mindeststärke in Bit muss eine ganze Zahl zwischen 0 und 200 sein.', $error->message);
    }

    public function testALeagueAdminSeesOnlyTheRefusal(): void
    {
        $this->permissions->replace(1, []);
        $html = (new SystemSettingsController($this->settings, new SystemSettingsView()))->page('');

        self::assertStringContainsString('Dafür fehlt diesem Konto das Recht „Systemadmin“.', $html);
        self::assertStringNotContainsString('min_bits', $html);
    }
}
