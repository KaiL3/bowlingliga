<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Draws a simple line chart as inline SVG (R39.3, R39.4, R41.4): one value per
 * matchday, optionally as steps (a value that holds for a whole matchday),
 * with a second, dashed line and with a series of single dots. Missing values
 * (null) leave a gap. The axis can be turned upside down (rank 1 on top) and
 * given a fixed range.
 *
 * No script and no library: the colours come from the stylesheet (classes
 * `chart__*` with the design tokens), so the chart follows style and colour
 * mode. The SVG is only a picture (`role="img"` with label); the values are
 * in a table next to it.
 */
final class SvgLineChart
{
    private const WIDTH   = 480;
    private const HEIGHT  = 220;
    private const LEFT    = 40;
    private const RIGHT   = 12;
    private const TOP     = 12;
    private const BOTTOM  = 28;
    private const STEPS   = [1, 2, 5, 10, 20, 25, 50, 100];

    /**
     * @param string           $label      Accessible name of the picture (plain text).
     * @param list<string>     $xLabels    Label per position (e.g. matchday counter).
     * @param list<float|null> $line       Values of the line, one per position.
     * @param list<float|null> $dots       Optional single values per position.
     * @param bool             $steps      Draw the line as steps.
     * @param callable(float): string $formatY Label of a y tick.
     * @param list<float|null> $second     Optional second line (dashed), one value per position.
     * @param bool             $invert     Smallest value on top (e.g. rank 1).
     * @param array{0: float, 1: float, 2: float}|null $range Fixed y range: minimum, maximum, tick step.
     */
    public function render(
        string $label,
        array $xLabels,
        array $line,
        array $dots = [],
        bool $steps = false,
        ?callable $formatY = null,
        array $second = [],
        bool $invert = false,
        ?array $range = null,
    ): string {
        $formatY ??= static fn (float $value): string => (string) (int) round($value);
        $count     = count($xLabels);
        $values    = array_values(array_filter([...$line, ...$second, ...$dots], static fn (?float $v): bool => $v !== null));
        if ($count === 0 || $values === []) {
            return '';
        }

        [$min, $max, $step] = $range ?? self::scale(min($values), max($values));

        $plotWidth  = self::WIDTH - self::LEFT - self::RIGHT;
        $plotHeight = self::HEIGHT - self::TOP - self::BOTTOM;
        $slot       = $plotWidth / $count;
        $x = static fn (int $i): float => self::LEFT + $slot * ($i + 0.5);
        $y = static function (float $v) use ($min, $max, $plotHeight, $invert): float {
            $share = ($v - $min) / ($max - $min);

            return self::TOP + $plotHeight * ($invert ? $share : 1 - $share);
        };

        $grid = '';
        for ($v = $min; $v <= $max + 1e-9; $v += $step) {
            $grid .= sprintf(
                '<line class="chart__grid" x1="%s" y1="%s" x2="%s" y2="%s"/><text class="chart__tick" x="%s" y="%s" text-anchor="end">%s</text>',
                self::n(self::LEFT),
                self::n($y($v)),
                self::n(self::WIDTH - self::RIGHT),
                self::n($y($v)),
                self::n(self::LEFT - 6),
                self::n($y($v) + 4),
                self::escape($formatY($v)),
            );
        }

        // Every label while they fit, otherwise every second or more.
        $every = max(1, (int) ceil($count / 16));
        $axis  = '';
        foreach ($xLabels as $i => $text) {
            if ($i % $every === 0 || $i === $count - 1) {
                $axis .= sprintf(
                    '<text class="chart__tick" x="%s" y="%s" text-anchor="middle">%s</text>',
                    self::n($x($i)),
                    self::n(self::HEIGHT - 8),
                    self::escape($text),
                );
            }
        }

        $paths = self::linePaths($second, $x, $y, 'chart__line chart__line--second');
        foreach (self::segments($line) as $segment) {
            $d = '';
            foreach ($segment as $n => $i) {
                $value = (float) $line[$i];
                if ($steps) {
                    // A value holds for the whole slot of its matchday.
                    $d .= $n === 0
                        ? sprintf('M%s %s', self::n($x($i) - $slot / 2), self::n($y($value)))
                        : sprintf(' V%s', self::n($y($value)));
                    $d .= sprintf(' H%s', self::n($x($i) + $slot / 2));
                } else {
                    $d .= sprintf('%s%s %s', $n === 0 ? 'M' : ' L', self::n($x($i)), self::n($y($value)));
                }
            }
            $paths .= '<path class="chart__line" d="' . $d . '"/>';
            if (! $steps && count($segment) === 1) {
                $only   = $segment[0];
                $paths .= sprintf('<circle class="chart__point" cx="%s" cy="%s" r="3.5"/>', self::n($x($only)), self::n($y((float) $line[$only])));
            }
        }

        $points = '';
        foreach ($dots as $i => $value) {
            if ($value !== null && $i < $count) {
                $points .= sprintf('<circle class="chart__dot" cx="%s" cy="%s" r="3.5"/>', self::n($x($i)), self::n($y($value)));
            }
        }

        return sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">%s%s%s%s</svg>',
            self::WIDTH,
            self::HEIGHT,
            self::escape($label),
            $grid,
            $axis,
            $paths,
            $points,
        );
    }

    /**
     * Paths of a plain line (no steps); a single value becomes a point.
     *
     * @param list<float|null>        $values
     * @param callable(int): float    $x
     * @param callable(float): float  $y
     */
    private static function linePaths(array $values, callable $x, callable $y, string $class): string
    {
        $paths = '';
        foreach (self::segments($values) as $segment) {
            $d = '';
            foreach ($segment as $n => $i) {
                $d .= sprintf('%s%s %s', $n === 0 ? 'M' : ' L', self::n($x($i)), self::n($y((float) $values[$i])));
            }
            $paths .= '<path class="' . $class . '" d="' . $d . '"/>';
            if (count($segment) === 1) {
                $paths .= sprintf('<circle class="chart__point chart__point--second" cx="%s" cy="%s" r="3"/>', self::n($x($segment[0])), self::n($y((float) $values[$segment[0]])));
            }
        }

        return $paths;
    }

    /**
     * Rounded y range with a tick step, so that about four to six grid lines
     * fit; a single value gets a range around it.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function scale(float $low, float $high): array
    {
        if ($high - $low < 1e-9) {
            $low  -= 5;
            $high += 5;
        }
        // Some air, so that no value sits on the edge of the chart.
        $air   = ($high - $low) * 0.08;
        $low  -= $air;
        $high += $air;

        $step = self::STEPS[count(self::STEPS) - 1];
        foreach (self::STEPS as $candidate) {
            if (($high - $low) / $candidate <= 5) {
                $step = $candidate;
                break;
            }
        }

        $min = max(0.0, floor($low / $step) * $step);
        $max = ceil($high / $step) * $step;
        if ($max - $min < 1e-9) {
            $max = $min + $step;
        }

        return [(float) $min, (float) $max, (float) $step];
    }

    /**
     * Positions with a value, split into runs without gaps.
     *
     * @param list<float|null> $values
     *
     * @return list<list<int>>
     */
    private static function segments(array $values): array
    {
        $segments = [];
        $current  = [];
        foreach ($values as $i => $value) {
            if ($value === null) {
                if ($current !== []) {
                    $segments[] = $current;
                }
                $current = [];
                continue;
            }
            $current[] = $i;
        }
        if ($current !== []) {
            $segments[] = $current;
        }

        return $segments;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
