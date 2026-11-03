<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Cache\CacheEntry;
use Bowlingliga\Cache\CacheInvalidation;
use Bowlingliga\Cache\CacheCategory;
use Bowlingliga\Cache\CacheStore;

/**
 * Persistence adapter for the cache tables `cache_*` (R35.2–R35.4).
 *
 * Implements the {@see CacheStore} port on the four cache tables
 * (`cache_average`, `cache_league_table`, `cache_matchday_table`,
 * `cache_honours`). The payload is stored/read as JSON; the table name
 * follows from the {@see CacheCategory}.
 *
 * The table names only come from the fixed enum {@see CacheCategory::table()}
 * and never from user input, so the table names (which cannot be
 * parameterised via prepared statements as identifiers) can be interpolated
 * safely. Values are bound via prepared statements throughout (R4.1).
 *
 * The cache contains only derived values and never raw data (R35.3); the
 * frozen handicap is never touched here (R35.5).
 */
final class CacheRepository extends AbstractRepository implements CacheStore
{
    public function find(CacheCategory $category, string $key): ?CacheEntry
    {
        $table = $category->table();

        $row = $this->findOne(
            "SELECT cache_key, season_id, from_matchday_number, payload, raw_hash, computed_at
             FROM {$table}
             WHERE cache_key = :key",
            ['key' => $key]
        );

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $contentData */
        $contentData = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        $calculatedAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['computed_at'])
            ?: new \DateTimeImmutable((string) $row['computed_at']);

        return new CacheEntry(
            (string) $row['cache_key'],
            $contentData,
            (string) $row['raw_hash'],
            $row['season_id'] !== null ? (int) $row['season_id'] : null,
            $row['from_matchday_number'] !== null ? (int) $row['from_matchday_number'] : null,
            $calculatedAt,
        );
    }

    public function write(CacheCategory $category, CacheEntry $entry): void
    {
        $table = $category->table();

        $this->transactional('Cache-Eintrag schreiben', function () use ($table, $entry): void {
            $this->perform(
                "INSERT INTO {$table}
                    (cache_key, season_id, from_matchday_number, payload, raw_hash, computed_at)
                 VALUES
                    (:key, :season_id, :from_matchday_number, :payload, :raw_hash, :computed_at)
                 ON DUPLICATE KEY UPDATE
                    season_id = VALUES(season_id),
                    from_matchday_number = VALUES(from_matchday_number),
                    payload = VALUES(payload),
                    raw_hash = VALUES(raw_hash),
                    computed_at = VALUES(computed_at)",
                [
                    'key'          => $entry->key,
                    'season_id'           => $entry->seasonId,
                    'from_matchday_number' => $entry->fromMatchdayCounter,
                    'payload'           => json_encode($entry->contentData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'raw_hash'            => $entry->rawHash,
                    'computed_at'        => $entry->calculatedAt->format('Y-m-d H:i:s'),
                ]
            );
        });
    }

    public function invalidate(CacheInvalidation $invalidation): void
    {
        $this->transactional('Cache invalidieren', function () use ($invalidation): void {
            foreach (CacheCategory::cases() as $category) {
                $this->invalidateCategory($category, $invalidation);
            }
        });
    }

    public function clear(CacheCategory $category): void
    {
        $table = $category->table();

        $this->transactional('Cache-Kategorie leeren', function () use ($table): void {
            $this->perform("DELETE FROM {$table}");
        });
    }

    /**
     * Removes the entries of a category affected by the invalidation (R35.4).
     *
     * Affected are entries of the same season whose `from_matchday_number` is
     * greater than or equal to the invalidated matchday. If no matchday is
     * given, all entries of the season are removed. Entries without a season
     * reference stay untouched, since they are not subject to a
     * matchday-dependent invalidation.
     */
    private function invalidateCategory(CacheCategory $category, CacheInvalidation $invalidation): void
    {
        $table = $category->table();

        if ($invalidation->fromMatchdayCounter === null) {
            $this->perform(
                "DELETE FROM {$table} WHERE season_id = :season_id",
                ['season_id' => $invalidation->seasonId]
            );

            return;
        }

        $this->perform(
            "DELETE FROM {$table}
             WHERE season_id = :season_id
               AND (from_matchday_number IS NULL OR from_matchday_number >= :from_matchday)",
            [
                'season_id'   => $invalidation->seasonId,
                'from_matchday' => $invalidation->fromMatchdayCounter,
            ]
        );
    }
}
