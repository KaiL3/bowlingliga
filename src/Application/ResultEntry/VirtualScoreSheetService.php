<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

use Bowlingliga\Domain\Ocr\RecognizedGame;

/**
 * Use case: opening the virtual score sheet of a fixture (R30.5–R30.13,
 * R22.1).
 *
 * Builds the uniform data model {@see VirtualScoreSheet} for exactly two teams
 * with the stored rows of the evening and 3 game fields each. The structure
 * is identical (R30.7) for manual entry (no rows yet, R30.6), resuming a
 * draft (R30.17) and OCR prefilling (R30.8): the same build method
 * {@see buildTeam()} is always used, only the field source differs. The
 * result entry page adds the roster players without results.
 *
 * For games marked blind the stored/entered net value remains the sheet value
 * (R30.10, R27.10); a derived blind reference value only serves display.
 * Fields needing review (ambiguous OCR recognition R30.9 or blind deviation
 * R30.11) are marked and trigger the overall note "rework required" (R30.9).
 *
 * The access check happens at controller level; this service is a pure read
 * preparation.
 */
final class VirtualScoreSheetService
{
    /** Anzahl Einzelspiele je Zeile (R30.5). */
    private const GAMES = 3;

    public function __construct(
        private readonly ScoreSheetReader $reader,
    ) {
    }

    /**
     * Opens the virtual score sheet of a fixture (R30.5).
     *
     * Without OCR data the fields are filled from the results already stored –
     * empty for an open fixture (R30.6) or restored from a draft (R30.17).
     *
     * Optionally OCR games recognized per team can be passed
     * ({@see $ocrHome}/{@see $ocrAway}); recognized position n fills the n-th
     * stored row and carries over blind flag and review need
     * (R30.8/R30.9/R30.11).
     *
     * @param list<RecognizedGame> $ocrHome recognized games of the home team
     * @param list<RecognizedGame> $ocrAway recognized games of the away team
     *
     * @throws ResultEntryException if the fixture does not exist.
     */
    public function openVirtualScoreSheet(
        int $fixtureId,
        array $ocrHome = [],
        array $ocrAway = [],
    ): VirtualScoreSheet {
        $rawData = $this->reader->findTeamRawData($fixtureId);
        if ($rawData === null) {
            throw ResultEntryException::matchdayContextMissing($fixtureId);
        }

        $matchdayId = $this->reader->findMatchdayId($fixtureId) ?? 0;

        $home = $this->buildTeam($rawData[0], $this->indexOcr($ocrHome));
        $away = $this->buildTeam($rawData[1], $this->indexOcr($ocrAway));

        $rework = $this->hasFieldsNeedingReview($home) || $this->hasFieldsNeedingReview($away);

        return new VirtualScoreSheet($fixtureId, $matchdayId, $home, $away, $rework);
    }

    /**
     * Builds a team from its stored rows × 3 fields (R30.5).
     *
     * For each field the source precedence applies: if there is an OCR game,
     * it is used (R30.8), otherwise the stored result (draft, R30.17),
     * otherwise the field stays empty (R30.6).
     *
     * @param array<string, RecognizedGame> $ocr indexed by "row-game"
     */
    private function buildTeam(ScoreSheetTeamRawData $rawData, array $ocr): ScoreSheetTeam
    {
        $rows = [];
        foreach ($rawData->rows as $index => $row) {
            $fields = [];
            for ($num = 1; $num <= self::GAMES; $num++) {
                $fields[] = $this->buildField($row['results'], $ocr[($index + 1) . '-' . $num] ?? null, $num);
            }

            $rows[] = new ScoreSheetRow($row['player_id'], $row['name'], $row['is_blind'], $fields);
        }

        return new ScoreSheetTeam($rawData->teamSeasonId, $rawData->teamLabel, $rows);
    }

    /**
     * Builds a single field from OCR data (precedence, R30.8) or from the
     * stored result (R30.17); otherwise empty (R30.6).
     *
     * @param array<int, array{net: int, is_blind: bool}> $results stored results of the row
     */
    private function buildField(array $results, ?RecognizedGame $recognized, int $num): ScoreSheetField
    {
        if ($recognized !== null) {
            return new ScoreSheetField(
                $num,
                $recognized->net,
                $recognized->isBlind,
                null,
                $recognized->needsReview,
            );
        }

        if (isset($results[$num])) {
            return new ScoreSheetField($num, $results[$num]['net'], $results[$num]['is_blind'], null, false);
        }

        return ScoreSheetField::blank($num);
    }

    /**
     * Indexes recognized games by "row-game" for fast access while building
     * the fields.
     *
     * @param list<RecognizedGame> $ocr
     *
     * @return array<string, RecognizedGame>
     */
    private function indexOcr(array $ocr): array
    {
        $isIndexed = [];
        foreach ($ocr as $game) {
            $isIndexed[$game->position . '-' . $game->gameNumber] = $game;
        }

        return $isIndexed;
    }

    /**
     * Tells whether a team contains at least one field needing review (R30.9).
     */
    private function hasFieldsNeedingReview(ScoreSheetTeam $team): bool
    {
        foreach ($team->rows as $row) {
            foreach ($row->fields as $field) {
                if ($field->needsReview) {
                    return true;
                }
            }
        }

        return false;
    }
}
