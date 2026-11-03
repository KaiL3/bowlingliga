<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\Cms\HomePagePostStore;

/**
 * Raw data repository for home page posts (table `home_page_post`, R33).
 *
 * Keeps per entry a title (1–200 characters) and a text content (1–5000
 * characters, ensured by a DB CHECK). Implements the
 * {@see HomePagePostStore} port for the writing use cases and additionally
 * offers the reads for the public display (R33.7). Write operations
 * transactional (R4.3), prepared statements (R4.1), reads unchanged (R4.5).
 */
final class HomePagePostRepository extends AbstractRepository implements HomePagePostStore
{
    public function create(string $title, string $text): int
    {
        return $this->transactional('Startseiten-Inhalt anlegen', function ($pdo) use ($title, $text): int {
            $this->perform(
                'INSERT INTO home_page_post (title, text) VALUES (:title, :text)',
                ['title' => $title, 'text' => $text]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    public function update(int $id, string $title, string $text): void
    {
        $this->transactional('Startseiten-Inhalt ändern', function () use ($id, $title, $text): void {
            $this->perform(
                'UPDATE home_page_post SET title = :title, text = :text WHERE id = :id',
                ['id' => $id, 'title' => $title, 'text' => $text]
            );
        });
    }

    public function delete(int $id): void
    {
        $this->transactional('Startseiten-Inhalt entfernen', function () use ($id): void {
            $this->perform('DELETE FROM home_page_post WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns all home page posts for the public display (R33.7).
     *
     * @return list<array{title: string, text: string}>
     */
    public function findAllContents(): array
    {
        $rows = $this->findAll(
            'SELECT title, text FROM home_page_post ORDER BY id DESC'
        );

        return array_map(
            static fn (array $row): array => [
                'title' => (string) $row['title'],
                'text'  => (string) $row['text'],
            ],
            $rows
        );
    }

    /**
     * Returns all home page posts including id for editing in the admin area
     * (R33.2, R33.3), newest first.
     *
     * @return list<array{id: int, title: string, text: string}>
     */
    public function findAllWithId(): array
    {
        $rows = $this->findAll(
            'SELECT id, title, text FROM home_page_post ORDER BY id DESC'
        );

        return array_map(
            static fn (array $row): array => [
                'id'    => (int) $row['id'],
                'title' => (string) $row['title'],
                'text'  => (string) $row['text'],
            ],
            $rows
        );
    }

    /**
     * Returns a single home page post or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, title, text FROM home_page_post WHERE id = :id',
            ['id' => $id]
        );
    }
}
