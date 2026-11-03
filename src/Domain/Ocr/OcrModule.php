<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Ocr;

/**
 * OCR module for recognizing and assigning score sheets (R3, R32.4).
 *
 * The module encapsulates the domain core of the OCR processing, which can be
 * tested deterministically, and separates it from the external OCR engine:
 *
 * 1. Format and size validation of the upload (JPEG/PNG/PDF, ≤ 10 MB, R3.1/2).
 * 2. Text recognition via the {@see TextRecognizer} port (Tesseract in
 *    production); a failure/timeout is signalled as {@see OcrException}
 *    (R3.14).
 * 3. Interpretation of the recognized raw text into structured
 *    {@see RecognizedGameData}: net values, blind marker ("X" vs. digit 1–4,
 *    R3.7/8), date and team names; ambiguous values are flagged as needing
 *    review (R3.9).
 * 4. Assignment to matchday/fixture via date + team names (R3.3–5) and
 *    merging of split score sheets for a lot draw (R3.18).
 *
 * Expected line format of the recognized raw text (after preprocessing by
 * the adapter), one entry per line:
 * - `DATUM: <DD.MM.YYYY>` – matchday date.
 * - `TEAM: <team name>` – one team name involved (twice per sheet).
 * - `<position> <game> <net> <marker>` – a game, e.g. `1 1 178 X`
 *   (position 1, game 1, net 178, blind) or `2 3 154 2` (position 2, game 3,
 *   net 154, order digit, not blind). A `?` as marker or an unreadable net
 *   value (`-`) flags the game as needing review (R3.9).
 *
 * This module is free of side effects; storing the original score sheet
 * (R3.15–17) and the confirmed results (R3.13) happens elsewhere.
 */
final class OcrModule
{
    /** Maximum file size 10 MB (R3.1, R3.2). */
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /** Supported file extensions (R3.1). */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    /** Supported MIME types (R3.1). */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(
        private readonly TextRecognizer $textRecognizer,
    ) {
    }

    /**
     * Recognizes a score sheet and returns structured game data that is not
     * yet stored (R3.1, R3.6–R3.11).
     *
     * @throws OcrException for an unsupported format/too large file (R3.2)
     *         or a failed/aborted recognition (R3.14).
     */
    public function recognizeScoreSheet(UploadedFile $file): RecognizedGameData
    {
        $this->ensureFormatAndSize($file);

        $rawText = $this->textRecognizer->recognizeText($file);

        return $this->interpret($rawText);
    }

    /**
     * Assigns recognized game data to a matchday and a fixture (R3.3–R3.5).
     *
     * The fixtures of the matchday matching the recognized date are
     * considered. If exactly one fixture matches the recognized team names,
     * the assignment is unique (R3.3). If several match or none can be
     * distinguished by the names, the selection is left to the administrator
     * (R3.4). If no matchday exists for the date, the assignment is suspended
     * (R3.5).
     */
    public function assign(RecognizedGameData $data, MatchdayAssignmentReader $reader): AssignmentResult
    {
        if ($data->date === null) {
            return AssignmentResult::noMatchday();
        }

        $candidates = $reader->findFixturesByDate($data->date);
        if ($candidates === []) {
            return AssignmentResult::noMatchday();
        }

        $matchdayId = $candidates[0]['matchday_id'];

        // Determine matching fixtures by the recognized team names.
        $matching = array_values(array_filter(
            $candidates,
            fn (array $k): bool => $this->teamNamesMatch($data->teamNames, $k['team_names'])
        ));

        if (count($matching) === 1) {
            return AssignmentResult::unique(
                $matching[0]['matchday_id'],
                $matching[0]['fixture_id']
            );
        }

        // None or several matching: manual selection from all fixtures
        // of the day (R3.4).
        $ids = array_map(static fn (array $k): int => $k['fixture_id'], $candidates);

        return AssignmentResult::ambiguous($matchdayId, array_values($ids));
    }

    /**
     * Merges the parts of a lot draw score sheet split in the middle into one
     * complete record (R3.18, R32.4).
     *
     * @param list<RecognizedGameData> $parts at least one part
     *
     * @throws \InvalidArgumentException if no parts are passed.
     */
    public function mergeShared(array $parts): RecognizedGameData
    {
        if ($parts === []) {
            throw new \InvalidArgumentException('Es müssen mindestens ein Spielzettel-Teil übergeben werden.');
        }

        $merged = $parts[0];
        for ($i = 1, $quantity = count($parts); $i < $quantity; $i++) {
            $merged = $merged->mergeWith($parts[$i]);
        }

        return $merged;
    }

    /**
     * Validates file format and size (R3.2).
     *
     * @throws OcrException
     */
    private function ensureFormatAndSize(UploadedFile $file): void
    {
        if ($file->sizeInBytes() > self::MAX_SIZE_BYTES) {
            throw OcrException::fileTooLarge($file->sizeInBytes(), self::MAX_SIZE_BYTES);
        }

        $extensionOk = in_array($file->extension(), self::ALLOWED_EXTENSIONS, true);
        $mimeOk   = in_array(strtolower($file->mimeType), self::ALLOWED_MIME, true);

        if (! $extensionOk || ! $mimeOk) {
            throw OcrException::unsupportedFormat($file->mimeType !== '' ? $file->mimeType : $file->extension());
        }
    }

    /**
     * Interprets the recognized raw text line by line into structured game
     * data (R3.6–R3.11).
     */
    private function interpret(string $rawText): RecognizedGameData
    {
        $date = null;
        $dateNeedsReview = false;
        $teamNames = [];
        $games = [];

        foreach (preg_split('/\R/', $rawText) ?: [] as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }

            if (preg_match('/^DATUM:\s*(.+)$/i', $row, $m) === 1) {
                [$date, $dateNeedsReview] = $this->parseDate(trim($m[1]));
                continue;
            }

            if (preg_match('/^TEAM:\s*(.+)$/i', $row, $m) === 1) {
                $teamNames[] = trim($m[1]);
                continue;
            }

            $game = $this->parseGame($row);
            if ($game !== null) {
                $games[] = $game;
            }
        }

        return new RecognizedGameData($date, array_values($teamNames), $games, $dateNeedsReview);
    }

    /**
     * Parses a date in the format DD.MM.YYYY into Y-m-d.
     *
     * @return array{0: string|null, 1: bool} normalised date (or null) and
     *         whether it needs review (true if not reliably readable, R3.9)
     */
    private function parseDate(string $raw): array
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m) === 1) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);

            // Check validity (e.g. rule out 31.02.).
            [$year, $month, $day] = array_map('intval', explode('-', $date));
            if (checkdate($month, $day, $year)) {
                return [$date, false];
            }
        }

        // Not reliably readable: flag as needing review (R3.9).
        return [null, true];
    }

    /**
     * Parses a game line `<position> <game> <net> <marker>`.
     *
     * Returns null if the line does not match the expected pattern. The marker
     * decides on blind marking (R3.7/8) and review need (R3.9).
     */
    private function parseGame(string $row): ?RecognizedGame
    {
        if (preg_match('/^(\d)\s+(\d)\s+(\d{1,3}|-)\s*(\S*)$/', $row, $m) !== 1) {
            return null;
        }

        $position      = (int) $m[1];
        $gameNumber = (int) $m[2];
        $netRaw      = $m[3];
        $marker    = $m[4];

        if ($position < 1 || $position > 4 || $gameNumber < 1 || $gameNumber > 3) {
            return null;
        }

        $needsReview = false;

        // Net: "-" or outside 0–300 needs review (R3.9).
        $net = null;
        if ($netRaw === '-') {
            $needsReview = true;
        } else {
            $value = (int) $netRaw;
            if ($value < 0 || $value > 300) {
                $needsReview = true;
            } else {
                $net = $value;
            }
        }

        // Marker: "X" = blind (R3.7); digit 1–4 = order, not blind
        // (R3.8); anything else (e.g. "?") = ambiguous, needs review (R3.9).
        $isBlind = false;
        if ($marker === 'X' || $marker === 'x') {
            $isBlind = true;
        } elseif ($marker === '' || preg_match('/^[1-4]$/', $marker) === 1) {
            // empty marker or valid order digit: not blind.
            $isBlind = false;
        } else {
            $needsReview = true;
        }

        return new RecognizedGame($position, $gameNumber, $net, $isBlind, $needsReview);
    }

    /**
     * Checks whether the recognized team names match the team names of a
     * fixture (regardless of order, case and surrounding whitespace).
     *
     * @param list<string> $recognized
     * @param list<string> $fixture
     */
    private function teamNamesMatch(array $recognized, array $fixture): bool
    {
        if (count($recognized) < 2 || count($fixture) < 2) {
            return false;
        }

        $norm = static fn (array $names): array => array_map(
            static fn (string $n): string => mb_strtolower(trim($n)),
            $names
        );

        $recognizedNorm = $norm($recognized);
        $fixtureNorm = $norm($fixture);
        sort($recognizedNorm);
        sort($fixtureNorm);

        return $recognizedNorm === $fixtureNorm;
    }
}
