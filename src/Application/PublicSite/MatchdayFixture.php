<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PublicSite;

use Bowlingliga\Domain\AverageList\AverageList;
use Bowlingliga\Domain\Points\PointsRules;

/**
 * A fixture of a matchday for the public detail view (R15.1, R2.1, R22.1).
 *
 * Corresponds to the content of one tab of the detail view: the lane pair as
 * tab name (R18), the digital score sheet view of both teams (who played how,
 * {@see ScoreSheetTeamView}), the average lists of both teams as of the
 * matchday (R2.1) and – if present – the id of the uploaded original score
 * sheet (R15.4).
 *
 * Pure, immutable value object.
 */
final class MatchdayFixture
{
    /**
     * @param int                $fixtureId            Fixture id
     * @param string|null        $lanes                Lanes of the lane pair ("1/2"), null without lane assignment
     * @param ScoreSheetTeamView $home                 Score sheet home
     * @param ScoreSheetTeamView $away                 Score sheet away
     * @param AverageList        $averageListHome      Average list home (R2.1)
     * @param AverageList        $averageListAway      Average list away (R2.1)
     * @param int|null           $originalScoreSheetId Id of the original score sheet or null (R15.4)
     * @param PointsRules        $points               Points rules of the season (R17.11)
     */
    public function __construct(
        public readonly int $fixtureId,
        public readonly ?string $lanes,
        public readonly ScoreSheetTeamView $home,
        public readonly ScoreSheetTeamView $away,
        public readonly AverageList $averageListHome,
        public readonly AverageList $averageListAway,
        public readonly ?int $originalScoreSheetId,
        public readonly PointsRules $points = new PointsRules(),
    ) {
    }

    public function hasOriginalScoreSheet(): bool
    {
        return $this->originalScoreSheetId !== null;
    }
}
