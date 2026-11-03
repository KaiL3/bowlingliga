<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Printing\PrintTexts;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * {@see PrintTexts} from the language catalogs.
 */
final class CatalogPrintTexts implements PrintTexts
{
    private readonly Formatter $format;

    public function __construct(private readonly Translator $t)
    {
        $this->format = new Formatter($t);
    }

    public function language(): string
    {
        return $this->t->language();
    }

    public function text(string $key, array $params = []): string
    {
        return $this->t->t($key, $params);
    }

    public function date(string $iso): string
    {
        return $this->format->date($iso);
    }

    public function number(float $value, int $decimals): string
    {
        return $this->format->number($value, $decimals);
    }
}
