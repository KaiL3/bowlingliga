<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;

/**
 * Display texts for the stored enum values (gender, matchday type,
 * completion status) in the visitor's language. The database and the code
 * use English values; the pages show these labels (catalog keys `value.*`).
 * Unknown values are returned unchanged.
 */
final class ValueLabels
{
    private const GENDER = ['male', 'female'];

    private const MATCHDAY_TYPE = ['regular', 'positionday', 'lot_draw'];

    private const COMPLETION_STATUS = ['open', 'in_progress', 'complete'];

    public function __construct(private readonly Translator $t = new Translator())
    {
    }

    public function gender(string $value): string
    {
        return in_array($value, self::GENDER, true) ? $this->t->t('value.gender.' . $value) : $value;
    }

    public function matchdayType(string $value): string
    {
        return in_array($value, self::MATCHDAY_TYPE, true) ? $this->t->t('value.matchday_type.' . $value) : $value;
    }

    public function completionStatus(string $value): string
    {
        return in_array($value, self::COMPLETION_STATUS, true) ? $this->t->t('value.completion_status.' . $value) : $value;
    }
}
