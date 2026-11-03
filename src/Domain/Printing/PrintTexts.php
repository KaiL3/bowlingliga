<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Printing;

/**
 * Texts, dates and numbers of a printed score sheet in one language. The
 * language is chosen per print (by default the season's language), not by
 * the administrator's own language: the sheet is read by the players.
 *
 * Keys are those of the catalogs (`print.*`); the implementation lives in
 * the presentation layer, so the printing domain stays free of I/O.
 */
interface PrintTexts
{
    /** Language code for `<html lang>` ("de", "en"). */
    public function language(): string;

    /**
     * Plain text of a key with its placeholders replaced (not escaped).
     *
     * @param array<string, string|int|float> $params
     */
    public function text(string $key, array $params = []): string;

    /** A date given as `YYYY-MM-DD`. */
    public function date(string $iso): string;

    public function number(float $value, int $decimals): string;
}
