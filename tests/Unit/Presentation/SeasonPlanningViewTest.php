<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\SeasonPlanning\PlannedFixture;
use Bowlingliga\Application\SeasonPlanning\PlannedMatchday;
use Bowlingliga\Application\SeasonPlanning\PlanningPreview;
use Bowlingliga\Domain\Message;
use Bowlingliga\Presentation\SeasonPlanningView;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see SeasonPlanningView} (task 35.2).
 *
 * Validates: R18.9, R18.10, R9.13, R22.5
 */
final class SeasonPlanningViewTest extends TestCase
{
    private const CSRF = '<input type="hidden" name="csrf_token" value="t">';

    public function testDeviationOverviewPerTeamWithSpread(): void
    {
        $preview = new PlanningPreview(
            1,
            [new PlannedMatchday(1, 'regular', [new PlannedFixture(11, null, 2)])],
            [new Message('lanes.warning.uneven_proven', ['spread' => 2])],
            [11 => ['usage' => [1 => 3, 2 => 1], 'spread' => 2]],
        );

        $html = (new SeasonPlanningView())->renderPreview(
            self::CSRF,
            ['id' => 1, 'name' => 'Saison <2030>'],
            $preview,
            [0 => 'Geisterteam', 11 => 'Adler'],
            str_repeat('a', 64),
            false,
        );

        self::assertStringContainsString('Abweichungen der Bahnverteilung', $html);
        self::assertStringContainsString('<th scope="col">Bahn 3/4</th>', $html);
        self::assertStringContainsString('<tr><th scope="row">Adler</th><td>3</td><td>1</td><td>2</td></tr>', $html);
        self::assertStringContainsString('Adler – Geisterteam', $html);
        self::assertStringContainsString('Saison &lt;2030&gt;', $html, 'The season name is escaped.');
        self::assertStringContainsString(self::CSRF, $html);
    }

    public function testPositiondayWithoutFixturesWithNote(): void
    {
        $preview = new PlanningPreview(1, [new PlannedMatchday(10, 'positionday', [])], []);

        $html = (new SeasonPlanningView())->renderPreview(
            self::CSRF,
            ['id' => 1, 'name' => 'S'],
            $preview,
            [0 => 'Geisterteam'],
            str_repeat('a', 64),
            true,
        );

        self::assertStringContainsString('aus dem Tabellenstand gebildet', $html);
        self::assertStringContainsString('vollständig ersetzt', $html);
        self::assertStringNotContainsString('name="confirmed"', $html, 'Without a warning no confirmation is required.');
    }
}
