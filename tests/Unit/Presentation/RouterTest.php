<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\Router;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see Router} (task 28.1, step 6).
 *
 * Validates: R14.4, R15.1, R28 (separation public/admin)
 *
 * Checks the exact resolution of public and administrative routes and the
 * new resolution of parameterised detail routes with a numeric id segment
 * (e.g. "/matchday/7").
 */
final class RouterTest extends TestCase
{
    public function testPublicRouteIsResolved(): void
    {
        $router = new Router();
        $router->public('/matchdays', static fn (): string => 'liste');

        $handler = $router->resolve('/matchdays');

        self::assertIsCallable($handler);
        self::assertSame('liste', $handler());
    }

    public function testAdminRouteIsUnderAdminPrefix(): void
    {
        $router = new Router();
        $router->admin('/', static fn (): string => 'admin');

        self::assertTrue($router->isAdminPath('/admin'));
        self::assertNotNull($router->resolve('/admin'));
        self::assertNull($router->resolve('/nicht-vorhanden'));
    }

    public function testIdRouteExtractsNumericId(): void
    {
        $router = new Router();
        $router->publicWithId('/matchday', static fn (int $id): string => 'detail-' . $id);

        $handler = $router->resolveWithId('/matchday/7');

        self::assertIsCallable($handler);
        self::assertSame('detail-7', $handler());
    }

    public function testIdRouteIgnoresNonNumericSegment(): void
    {
        $router = new Router();
        $router->publicWithId('/matchday', static fn (int $id): string => 'detail-' . $id);

        self::assertNull($router->resolveWithId('/matchday/abc'), 'Non-numeric segments do not trigger an id route.');
        self::assertNull($router->resolveWithId('/matchday'), 'Without an id segment no resolution.');
        self::assertNull($router->resolveWithId('/matchday/7/extra'), 'More than one segment does not trigger an id route.');
    }
}
