<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Result of generating printed score sheets in the admin area (task 35.4,
 * R19).
 *
 * Carries one of three results to the delivering layer:
 * - a finished PDF (binary string, MIME `application/pdf`) if mPDF is
 *   available (R19.4),
 * - otherwise the print-optimised HTML as fallback (R19.4),
 * - or an error message for the user, e.g. for a missing lane assignment
 *   (R19.5) or a fixture that cannot be found.
 *
 * Immutable value object without side effects.
 */
final class PrintScoreSheetResult
{
    public const TYPE_PDF   = 'pdf';
    public const TYPE_HTML  = 'html';
    public const TYPE_ERROR = 'error';

    private function __construct(
        public readonly string $type,
        public readonly string $content,
        public readonly string $mimeType,
    ) {
    }

    /**
     * Fertiges PDF-Dokument (R19.4).
     */
    public static function pdf(string $pdfBytes): self
    {
        return new self(self::TYPE_PDF, $pdfBytes, 'application/pdf');
    }

    /**
     * Print-optimised HTML view as fallback if no PDF can be generated
     * (R19.4).
     */
    public static function html(string $html): self
    {
        return new self(self::TYPE_HTML, $html, 'text/html; charset=utf-8');
    }

    /**
     * Benutzergerechte Fehlermeldung (z. B. fehlende LaneAssignment, R19.5).
     */
    public static function error(string $message): self
    {
        return new self(self::TYPE_ERROR, $message, 'text/plain; charset=utf-8');
    }

    public function isError(): bool
    {
        return $this->type === self::TYPE_ERROR;
    }
}
