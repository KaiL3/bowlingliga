<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\SeasonPlanner;

/**
 * Immutable value object of a matchday of the generated schedule (R9.2,
 * R9.13).
 *
 * A matchday carries a sequential number starting at 1 (matchday counter) and
 * the fixtures assigned to it; no team is scheduled more than once on a
 * matchday (R9.2). Every tenth matchday (10, 20, 30, …, interval configurable)
 * is automatically marked as positionday according to R31 by the
 * season planner (R9.13); all other matchdays are regular. In the planning
 * preview the administrator can set every matchday to regular, positionday
 * or lot draw.
 *
 * Positiondays and lot draws are special days without fixed fixtures: the
 * fixtures of a positionday are formed later from the standings (R31), those
 * of a lot draw are drawn on site and entered by the administrator (R32).
 * The round robin only runs over the regular matchdays.
 */
final class Matchday
{
    public const TYPE_REGULAR = 'regular';
    public const TYPE_POSITIONDAY = 'positionday';
    public const TYPE_LOT_DRAW = 'lot_draw';

    public const TYPES = [self::TYPE_REGULAR, self::TYPE_POSITIONDAY, self::TYPE_LOT_DRAW];

    /** Default: every tenth matchday is a positionday (R9.13); configurable per season. */
    public const DEFAULT_POSITIONDAY_INTERVAL = 10;

    /**
     * @param int           $number   Sequential number of the matchday starting
     *                                at 1 (matchday counter). >= 1.
     * @param list<Fixture> $fixtures The fixtures assigned to the matchday
     *                                (R9.2). No team takes part more than once.
     * @param string        $type     Matchday type: regular, positionday or
     *                                lot draw (R9.13, R32).
     *
     * @throws \InvalidArgumentException If the number is less than 1, the type
     *                                   is unknown or a team is scheduled more
     *                                   than once on this matchday.
     */
    public function __construct(
        public readonly int $number,
        public readonly array $fixtures,
        public readonly string $type,
    ) {
        if ($number < 1) {
            throw new \InvalidArgumentException('Die Nummer eines Spieltags muss mindestens 1 betragen, gegeben: ' . $number);
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unbekannter Spieltag-Typ: ' . $type);
        }

        $this->checkNoTeamDuplicated($fixtures);
    }

    /**
     * Creates a matchday and derives the type automatically from the number:
     * every n-th matchday becomes a positionday, all others are regular
     * (R9.13). With interval 0 there are no positiondays.
     *
     * @param list<Fixture> $fixtures
     */
    public static function fromNumber(int $number, array $fixtures, int $positiondayInterval = self::DEFAULT_POSITIONDAY_INTERVAL): self
    {
        return new self($number, $fixtures, self::typeForNumber($number, $positiondayInterval));
    }

    /**
     * Type that the planner gives a matchday by default: positionday on every
     * n-th matchday, otherwise regular (interval 0 = no positiondays).
     */
    public static function typeForNumber(int $number, int $positiondayInterval = self::DEFAULT_POSITIONDAY_INTERVAL): string
    {
        return $positiondayInterval > 0 && $number % $positiondayInterval === 0
            ? self::TYPE_POSITIONDAY
            : self::TYPE_REGULAR;
    }

    /**
     * Tells whether this matchday is a positionday according to R31
     * (R9.13).
     */
    public function isPositionday(): bool
    {
        return $this->type === self::TYPE_POSITIONDAY;
    }

    /**
     * Positionday or lot draw: a matchday without fixtures fixed in the plan.
     */
    public function isSpecialDay(): bool
    {
        return $this->type !== self::TYPE_REGULAR;
    }

    /**
     * Ensures that no team is scheduled more than once on a matchday (R9.2).
     *
     * @param list<Fixture> $fixtures
     */
    private function checkNoTeamDuplicated(array $fixtures): void
    {
        $seen = [];

        foreach ($fixtures as $fixture) {
            foreach ([$fixture->home->id, $fixture->away->id] as $teamId) {
                if (isset($seen[$teamId])) {
                    throw new \InvalidArgumentException(
                        'Ein Team darf an einem Spieltag nicht mehrfach eingeplant sein, betroffen: ' . $teamId
                    );
                }

                $seen[$teamId] = true;
            }
        }
    }
}
