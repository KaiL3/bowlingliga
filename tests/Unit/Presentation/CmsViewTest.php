<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapTableService;
use Bowlingliga\Presentation\CmsView;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see CmsView} (task 29.1/29.2).
 *
 * Validates: R33.7, R33.9, R34.2, R34.6
 *
 * Checks the read-only display of the home page posts (empty when there are
 * none) and the rules (note for missing content).
 */
final class CmsViewTest extends TestCase
{
    private CmsView $view;

    protected function setUp(): void
    {
        $this->view = new CmsView();
    }

    public function testHomePagePostsAreRendered(): void
    {
        $html = $this->view->renderHomePagePosts([
            ['title' => 'Willkommen', 'text' => '<p>Die Saison startet.</p>'],
        ]);

        self::assertStringContainsString('Willkommen', $html);
        self::assertStringContainsString('<p>Die Saison startet.</p>', $html, 'Cleaned text is output unchanged.');
    }

    public function testWithoutHomePagePostsEmptyString(): void
    {
        // R33.9: without posts nothing is added.
        self::assertSame('', $this->view->renderHomePagePosts([]));
    }

    public function testRulesAreRendered(): void
    {
        $html = $this->view->renderRules('<p>Regel 1</p>');

        self::assertStringContainsString('Spielregeln', $html);
        self::assertStringContainsString('<p>Regel 1</p>', $html);
    }

    public function testRulesNoteOnMissingContent(): void
    {
        // R34.6: note if no rules are stored yet.
        self::assertStringContainsString('noch keine Spielregeln', $this->view->renderRules(null));
        self::assertStringContainsString('noch keine Spielregeln', $this->view->renderRules('   '));
    }

    public function testWithoutHandicapTableNoTabs(): void
    {
        self::assertStringNotContainsString('role="tablist"', $this->view->renderRules('<p>Regel 1</p>'));
    }

    public function testHandicapTableAsSecondTab(): void
    {
        $table = (new HandicapTableService())->build(HandicapFormula::standard(), 190, 64);

        $html = $this->view->renderRules('<p>Regel 1</p>', $table, 'Saison <2026>');

        self::assertStringContainsString('role="tablist"', $html);
        self::assertStringContainsString('aria-controls="rules-panel-text"', $html);
        self::assertStringContainsString('aria-controls="rules-panel-handicap"', $html);
        self::assertStringContainsString('<p>Regel 1</p>', $html);
        self::assertStringContainsString('/assets/js/tabs.js', $html);
        self::assertStringContainsString('HDC = (190 − Schnitt) × 0,8, gerundet, höchstens 64.', $html);
        self::assertStringContainsString('<tr><td>≤ 110</td><td>64</td></tr>', $html);
        self::assertStringContainsString('<tr><td>150</td><td>32</td></tr>', $html);
        self::assertStringContainsString('<tr><td>≥ 190</td><td>0</td></tr>', $html);
        self::assertSame(3, substr_count($html, '<table class="table table--numbers">'), 'Drei Spalten.');
        self::assertStringContainsString('Saison &lt;2026&gt;', $html, 'The season name is escaped.');
    }

    public function testHandicapTableAlsoWithoutRulesText(): void
    {
        $table = (new HandicapTableService())->build(HandicapFormula::standard(), 190, 64);

        $html = $this->view->renderRules(null, $table, 'Saison 2026');

        self::assertStringContainsString('noch keine Spielregeln', $html);
        self::assertStringContainsString('Handicap-Tabelle', $html);
    }
}
