<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\PublicNavigation;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the public top-level navigation (task 28.2).
 *
 * Validates: R28.1, R28.2, R28.4, R28.5, R28.6, R28.15
 *
 * Checks that exactly the intended top-level items are generated (home page,
 * matchdays, league table, honours, rules), that the selected season is kept
 * on the season-related items (R28.2) and that detail contents (average lists,
 * matchday table, score sheet) do not appear as navigation items of their own
 * (R28.4).
 */
final class PublicNavigationTest extends TestCase
{
    private PublicNavigation $navigation;

    protected function setUp(): void
    {
        $this->navigation = new PublicNavigation();
    }

    /**
     * R28.1, R28.2, R28.5, R28.6, R28.15: exactly the five intended top-level
     * items in the intended order.
     */
    public function testContainsExactlyTheIntendedItems(): void
    {
        $texts = array_column($this->navigation->entries(null), 'text');

        self::assertSame(
            ['Startseite', 'Spieltage', 'Ligatabelle', 'Ehrungen', 'Spielregeln'],
            $texts
        );
    }

    /**
     * R28.2: with a selected season the season-related items carry the query
     * parameter `season`.
     */
    public function testSeasonIsKeptOnSeasonItems(): void
    {
        $entries = $this->indexByText($this->navigation->entries(7));

        self::assertSame('/matchdays?season=7', $entries['Spieltage']);
        self::assertSame('/table?season=7', $entries['Ligatabelle']);
        self::assertSame('/honours?season=7', $entries['Ehrungen']);
    }

    /**
     * R28.2: home page (last matchday and table of the season) and rules
     * (handicap table of the season) keep the selection as well.
     */
    public function testHomePageAndRulesKeepTheSeason(): void
    {
        $entries = $this->indexByText($this->navigation->entries(7));

        self::assertSame('/?season=7', $entries['Startseite']);
        self::assertSame('/rules?season=7', $entries['Spielregeln']);
    }

    /**
     * R28.2: without a selected season the season-related items carry no
     * parameter either.
     */
    public function testWithoutSeasonNoParameter(): void
    {
        $entries = $this->indexByText($this->navigation->entries(null));

        self::assertSame('/', $entries['Startseite']);
        self::assertSame('/matchdays', $entries['Spieltage']);
        self::assertSame('/table', $entries['Ligatabelle']);
        self::assertSame('/honours', $entries['Ehrungen']);
        self::assertSame('/rules', $entries['Spielregeln']);
    }

    /**
     * An invalid (not positive) season id is treated like "no season" and
     * appends no parameter.
     */
    public function testInvalidSeasonIsIgnored(): void
    {
        $entries = $this->indexByText($this->navigation->entries(0));

        self::assertSame('/matchdays', $entries['Spieltage']);
    }

    /**
     * R28.4: detail contents do not appear as top-level items of their own.
     */
    public function testNoDetailContentAsTopLevelItems(): void
    {
        $texts = array_map('strtolower', array_column($this->navigation->entries(7), 'text'));
        $hrefs = array_map('strtolower', array_column($this->navigation->entries(7), 'href'));
        $everything = implode(' ', [...$texts, ...$hrefs]);

        self::assertStringNotContainsString('schnittlist', $everything);
        self::assertStringNotContainsString('tagestabelle', $everything);
        self::assertStringNotContainsString('spielzettel', $everything);
    }

    /**
     * Indexes the navigation entries by their text to the href.
     *
     * @param list<array{href: string, text: string}> $entries
     *
     * @return array<string, string>
     */
    private function indexByText(array $entries): array
    {
        $indexed = [];
        foreach ($entries as $entry) {
            $indexed[$entry['text']] = $entry['href'];
        }

        return $indexed;
    }
}
