<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\SvgLineChart;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the inline SVG line chart of the player and team page.
 *
 * Validates: R39.3, R39.4, R41.4
 */
final class SvgLineChartTest extends TestCase
{
    private SvgLineChart $chart;

    protected function setUp(): void
    {
        $this->chart = new SvgLineChart();
    }

    public function testWithoutValuesNothingIsDrawn(): void
    {
        self::assertSame('', $this->chart->render('Leer', ['1', '2'], [null, null]));
        self::assertSame('', $this->chart->render('Leer', [], []));
    }

    public function testTheChartIsAnImageWithAnEscapedLabel(): void
    {
        $svg = $this->chart->render('Schnitt <Anna> & "Ben"', ['1', '2'], [150.0, 160.0]);

        self::assertStringStartsWith('<svg class="chart" viewBox="0 0 480 220" role="img"', $svg);
        self::assertStringContainsString('aria-label="Schnitt &lt;Anna&gt; &amp; &quot;Ben&quot;"', $svg);
        self::assertStringNotContainsString('<script', $svg);
    }

    public function testTheSecondLineIsDashedAndCountsForTheScale(): void
    {
        $svg = $this->chart->render('Punkte', ['1', '2'], [7.0, 8.0], second: [7.0, 30.0]);

        self::assertSame(1, substr_count($svg, '<path class="chart__line chart__line--second"'));
        self::assertStringContainsString('>30</text>', $svg, 'The scale reaches the higher second line.');
    }

    public function testAnInvertedChartWithFixedRangeShowsRankOneOnTop(): void
    {
        $svg = $this->chart->render('Platz', ['1', '2'], [1.0, 3.0], invert: true, range: [1.0, 3.0, 1.0]);

        // Rank 1 sits at the top edge of the plot (y = 12), rank 3 at the bottom (y = 192).
        self::assertStringContainsString('d="M', $svg);
        self::assertMatchesRegularExpression('/d="M[\d.]+ 12 L[\d.]+ 192"/', $svg);
        self::assertStringContainsString('y="16" text-anchor="end">1</text>', $svg, 'Tick 1 at the top.');
        self::assertStringNotContainsString('>0</text>', $svg, 'No rank 0.');
    }

    public function testAGapSplitsTheLine(): void
    {
        $svg = $this->chart->render('HDC', ['1', '2', '3', '4'], [40.0, 36.0, null, 30.0], [], true);

        self::assertSame(2, substr_count($svg, '<path class="chart__line"'), 'One path before and one after the gap.');
        self::assertMatchesRegularExpression('/d="M[\d.]+ [\d.]+ H[\d.]+ V[\d.]+ H[\d.]+"/', $svg, 'Steps: horizontal per matchday, vertical between them.');
    }

    public function testASingleValueOfALineGetsAPoint(): void
    {
        $svg = $this->chart->render('Schnitt', ['1'], [170.0]);

        self::assertStringContainsString('<circle class="chart__point"', $svg);
    }

    public function testDotsAndTicksAreDrawn(): void
    {
        $svg = $this->chart->render('Schnitt', ['1', '2', '3'], [160.0, 165.0, 170.0], [160.0, null, 180.0], false, static fn (float $v): string => 'y' . (int) $v);

        self::assertSame(2, substr_count($svg, '<circle class="chart__dot"'));
        self::assertStringContainsString('>y160<', $svg);
        self::assertStringContainsString('>y180<', $svg);
        foreach (['1', '2', '3'] as $label) {
            self::assertStringContainsString('text-anchor="middle">' . $label . '<', $svg);
        }
    }

    public function testManyMatchdaysShowOnlyEveryOtherLabel(): void
    {
        $labels = array_map('strval', range(1, 30));
        $svg    = $this->chart->render('HDC', $labels, array_fill(0, 30, 20.0), [], true);

        self::assertStringContainsString('text-anchor="middle">1<', $svg);
        self::assertStringNotContainsString('text-anchor="middle">2<', $svg);
        self::assertStringContainsString('text-anchor="middle">30<', $svg, 'The last matchday is always labelled.');
    }
}
