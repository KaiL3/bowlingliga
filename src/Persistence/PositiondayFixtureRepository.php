<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\Positionday\SeededPositiondayFixture;
use Bowlingliga\Application\Positionday\PositiondayFixtureStore;

/**
 * Persistence adapter for the formed positionday fixtures including the lane
 * assignment (R31.2, R31.5).
 *
 * Implements the {@see PositiondayFixtureStore} port. The whole operation
 * runs in one transaction (R4.3): first the existing (initially empty)
 * fixtures of the positionday are removed, then the fixtures formed from the
 * standings are created with team season assignment and – if present – lane
 * pair index. The season's lane pairs needed are ensured idempotently. If a
 * step fails, the data stays unchanged. Prepared statements (R4.1).
 */
final class PositiondayFixtureRepository extends AbstractRepository implements PositiondayFixtureStore
{
    /**
     * @param list<SeededPositiondayFixture> $fixtures
     */
    public function saveFixtures(int $matchdayId, array $fixtures): void
    {
        $this->transactional('Positionday-Paarungen speichern', function () use ($matchdayId, $fixtures): void {
            $seasonId = $this->findSeasonId($matchdayId);

            $lanePairIdByIndex = $this->ensureLanePairs($seasonId, $fixtures);

            // Bisherige (leere) Positionday-Teampaarungen entfernen, um Ersetzen
            // statt Duplizieren sicherzustellen.
            $this->perform(
                'DELETE FROM fixture WHERE matchday_id = :matchday_id',
                ['matchday_id' => $matchdayId]
            );

            foreach ($fixtures as $fixture) {
                $lanePairId = $fixture->lanePairIndex !== null
                    ? ($lanePairIdByIndex[$fixture->lanePairIndex] ?? null)
                    : null;

                $this->perform(
                    'INSERT INTO fixture
                        (matchday_id, home_team_season_id, away_team_season_id, lane_pair_id, completion_status)
                     VALUES (:matchday_id, :home_id, :away_id, :lane_pair_id, :status)',
                    [
                        'matchday_id' => $matchdayId,
                        'home_id'     => $fixture->homeTeamSeasonId,
                        'away_id'     => $fixture->awayTeamSeasonId,
                        'lane_pair_id' => $lanePairId,
                        'status'      => 'open',
                    ]
                );
            }
        });
    }

    private function findSeasonId(int $matchdayId): int
    {
        $row = $this->findOne(
            'SELECT season_id FROM matchday WHERE id = :id',
            ['id' => $matchdayId]
        );

        if ($row === null) {
            throw new \RuntimeException('Der Positionday-Spieltag existiert nicht: ' . $matchdayId);
        }

        return (int) $row['season_id'];
    }

    /**
     * Ensures the season's lane pairs needed and returns the mapping lane pair
     * index → lane pair id.
     *
     * @param list<SeededPositiondayFixture> $fixtures
     *
     * @return array<int, int>
     */
    private function ensureLanePairs(int $seasonId, array $fixtures): array
    {
        $indices = [];
        foreach ($fixtures as $fixture) {
            if ($fixture->lanePairIndex !== null) {
                $indices[$fixture->lanePairIndex] = true;
            }
        }

        $existingItems = $this->findAll(
            'SELECT id, lane_a FROM lane_pair WHERE season_id = :season_id',
            ['season_id' => $seasonId]
        );
        $idByLaneA = [];
        foreach ($existingItems as $row) {
            $idByLaneA[(int) $row['lane_a']] = (int) $row['id'];
        }

        $idByIndex = [];
        foreach (array_keys($indices) as $index) {
            $laneA = ($index - 1) * 2 + 1;
            $laneB = $laneA + 1;

            if (isset($idByLaneA[$laneA])) {
                $idByIndex[$index] = $idByLaneA[$laneA];
                continue;
            }

            $this->perform(
                'INSERT INTO lane_pair (season_id, lane_a, lane_b) VALUES (:season_id, :lane_a, :lane_b)',
                ['season_id' => $seasonId, 'lane_a' => $laneA, 'lane_b' => $laneB]
            );
            $idByIndex[$index] = (int) $this->database->pdo()->lastInsertId();
        }

        return $idByIndex;
    }
}
