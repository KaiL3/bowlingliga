<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\Media\ContentTextReader;

/**
 * Reads every text of the website that can hold images (R42.3). The title
 * of a home page post is stored escaped (R33.11) and comes back that way.
 */
final class ContentTextRepository extends AbstractRepository implements ContentTextReader
{
    public function texts(): array
    {
        $texts = [];
        foreach ($this->findAll('SELECT id, title, text FROM home_page_post ORDER BY id DESC') as $row) {
            $texts[] = ['area' => 'home_page', 'id' => (int) $row['id'], 'title' => (string) $row['title'], 'text' => (string) $row['text']];
        }
        foreach (['rules', 'privacy_policy', 'legal_notice'] as $table) {
            foreach ($this->findAll('SELECT text FROM `' . $table . '`') as $row) {
                $texts[] = ['area' => $table, 'id' => null, 'title' => null, 'text' => (string) $row['text']];
            }
        }

        return $texts;
    }
}
