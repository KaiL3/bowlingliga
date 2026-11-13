<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Media;

/**
 * Port: every text of the website that can hold images (R42.3) – the home
 * page posts, the rules, the privacy policy and the legal notice.
 */
interface ContentTextReader
{
    /**
     * @return list<array{area: string, id: int|null, title: string|null, text: string}>
     *         area: home_page | rules | privacy_policy | legal_notice;
     *         id and title only for a home page post
     */
    public function texts(): array;
}
