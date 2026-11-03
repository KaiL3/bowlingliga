<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\Cms\RulesStore;

/**
 * Raw data repository for the rules page (table `rules`, R34).
 *
 * Keeps a single text content (0–20000 characters, ensured by a DB CHECK).
 * Implements the {@see RulesStore} port and offers the read for the public
 * display (R34.2). There is at most one row; saving creates it or updates
 * the existing one. Write operations transactional (R4.3), prepared
 * statements (R4.1), reads unchanged (R4.5).
 */
final class RulesRepository extends AbstractRepository implements RulesStore
{
    public function save(string $text): void
    {
        $this->transactional('Spielregeln speichern', function () use ($text): void {
            $present = $this->findOne('SELECT id FROM rules ORDER BY id LIMIT 1');

            if ($present === null) {
                $this->perform('INSERT INTO rules (text) VALUES (:text)', ['text' => $text]);

                return;
            }

            $this->perform(
                'UPDATE rules SET text = :text WHERE id = :id',
                ['id' => (int) $present['id'], 'text' => $text]
            );
        });
    }

    /**
     * Returns the text content of the rules, or null if none has been entered
     * yet (R34.6).
     */
    public function findText(): ?string
    {
        $row = $this->findOne('SELECT text FROM rules ORDER BY id LIMIT 1');

        return $row !== null ? (string) $row['text'] : null;
    }
}
