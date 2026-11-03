<?php

declare(strict_types=1);

namespace Bowlingliga\I18n;

/**
 * Dates and numbers in the visitor's language. Names of weekdays and months,
 * the date pattern and the decimal separator come from the catalog, so a new
 * language needs no code:
 *
 * - `date.weekday.0` … `date.weekday.6` (Sunday first), `date.month.1` … `12`,
 * - `date.pattern` with `{d}`, `{dd}`, `{mm}`, `{month}`, `{yyyy}`,
 * - `date.pattern_weekday` and `date.pattern_weekday_long` with `{weekday}`
 *   and `{date}`,
 * - `number.decimal` (separator of the decimals, no thousands separator).
 */
final class Formatter
{
    public function __construct(private readonly Translator $translator = new Translator())
    {
    }

    /**
     * A date given as `YYYY-MM-DD`; an unreadable value is returned unchanged.
     */
    public function date(string $iso, bool $withWeekday = false): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        if ($date === false) {
            return $iso;
        }

        $text = $this->translator->t('date.pattern', [
            'd'     => $date->format('j'),
            'dd'    => $date->format('d'),
            'mm'    => $date->format('m'),
            'month' => $this->translator->t('date.month.' . $date->format('n')),
            'yyyy'  => $date->format('Y'),
        ]);

        return $withWeekday
            ? $this->translator->t('date.pattern_weekday', ['weekday' => $this->weekday((int) $date->format('w')), 'date' => $text])
            : $text;
    }

    /**
     * A date with its weekday separated by a comma, as on the public pages
     * ("Mi, 21.10.2026" / "Wed, 21 Oct 2026").
     */
    public function dateLong(string $iso): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        if ($date === false) {
            return $iso;
        }

        return $this->translator->t('date.pattern_weekday_long', [
            'weekday' => $this->weekday((int) $date->format('w')),
            'date'    => $this->date($iso),
        ]);
    }

    /** Short name of a weekday, 0 = Sunday. */
    public function weekday(int $day): string
    {
        return $this->translator->t('date.weekday.' . ($day % 7 + 7) % 7);
    }

    /** A number with a fixed number of decimals, without thousands separator. */
    public function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, $this->translator->t('number.decimal'), '');
    }
}
