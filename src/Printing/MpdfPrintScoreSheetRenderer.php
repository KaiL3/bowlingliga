<?php

declare(strict_types=1);

namespace Bowlingliga\Printing;

use Mpdf\Mpdf;

/**
 * Adapter for converting the printed score sheet HTML into an A4 landscape
 * PDF using mPDF (R19.4).
 *
 * The {@see \Bowlingliga\Domain\Printing\PrintScoreSheetService} generates
 * the print-optimised HTML; this adapter renders it into a PDF binary string.
 * mPDF is intended as a Composer dependency; if the library is not available
 * at runtime, {@see isAvailable()} reports this, so that the caller can
 * deliver the print-optimised HTML view instead (R19.4 allows a PDF OR a
 * print-optimised view).
 *
 * Generating the HTML deliberately stays in the pure domain service; this
 * adapter contains no layout or content logic and is thus a thin
 * infrastructure component.
 */
final class MpdfPrintScoreSheetRenderer
{
    /**
     * Tells whether the mPDF library is available at runtime.
     */
    public function isAvailable(): bool
    {
        return class_exists(Mpdf::class);
    }

    /**
     * Renders the given HTML into a PDF binary string in A4 landscape (R19.4).
     *
     * @throws \RuntimeException if mPDF is not available.
     */
    public function renderToPdf(string $html): string
    {
        if (! $this->isAvailable()) {
            // R19.4: fall back to the print-optimised HTML view.
            throw new \RuntimeException(
                'mPDF ist nicht verfügbar; das PDF kann nicht erzeugt werden. '
                . 'Alternativ kann die druckoptimierte HTML-Ansicht ausgeliefert werden.'
            );
        }

        // A4 landscape: the two team columns side by side
        // get more width this way; mPDF expects the format "A4-L" for this.
        // Temporary files in the system temp instead of under vendor/ (the
        // web server must not write there on the server).
        $mpdf = new Mpdf(['format' => 'A4-L', 'tempDir' => sys_get_temp_dir() . '/bowlingliga-mpdf']);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }
}
