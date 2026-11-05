<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\Import\PlayerCsvParser;
use Bowlingliga\I18n\CatalogImportVocabulary;
use Bowlingliga\I18n\Languages;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Presentation\PlayerImportView;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the help and the template of the player import (R6): both
 * use the column names of the visitor's language.
 */
final class PlayerImportViewTest extends TestCase
{
    private const SEASONS = [['id' => 1, 'name' => 'Saison 2026']];

    public function testTheFormNamesTheColumnsAndOffersTheTemplate(): void
    {
        $german = (new PlayerImportView(new Translator('de')))->renderForm('<input type="hidden">', self::SEASONS, 1);
        $english = (new PlayerImportView(new Translator('en')))->renderForm('<input type="hidden">', self::SEASONS, 1);

        self::assertStringContainsString('href="/admin/master-data/import-template"', $german);
        self::assertStringContainsString('Team;Name;Geschlecht;Vorjahr Schnitt;Vorjahr Spiele', $german);
        self::assertStringContainsString('<strong>Geschlecht</strong>: m oder w.', $german);
        self::assertStringContainsString('Team,Name,Gender,Previous average,Previous games', $english);
        self::assertStringContainsString('<strong>Gender</strong>: m or f.', $english);
    }

    public function testTheTemplateOfEveryLanguageReadsBackWithoutErrors(): void
    {
        $parser = new PlayerCsvParser(CatalogImportVocabulary::all());
        foreach (Languages::codes() as $language) {
            $template = (new PlayerImportView(new Translator($language)))->template();

            self::assertStringStartsWith("\xEF\xBB\xBF", $template, 'BOM for Excel.');
            $result = $parser->parse($template);
            self::assertSame([], $result['errors'], $language);
            self::assertCount(2, $result['rows'], $language);
            self::assertSame(143.64, $result['rows'][0]->previousAverage, $language);
        }
    }

    public function testTheTemplateFileNameFollowsTheLanguage(): void
    {
        self::assertSame('spieler-import.csv', (new PlayerImportView(new Translator('de')))->templateFileName());
        self::assertSame('player-import.csv', (new PlayerImportView(new Translator('en')))->templateFileName());
    }
}
