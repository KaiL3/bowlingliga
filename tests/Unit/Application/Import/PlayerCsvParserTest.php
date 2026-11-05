<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Import;

use Bowlingliga\Application\Import\PlayerCsvParser;
use Bowlingliga\I18n\CatalogImportVocabulary;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Tests\GermanText;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for reading the player import file.
 */
final class PlayerCsvParserTest extends TestCase
{
    private PlayerCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PlayerCsvParser(CatalogImportVocabulary::all());
    }

    public function testSemicolonFileWithPreviousSeason(): void
    {
        $result = $this->parser->parse("Team;Name;Geschlecht;Vorjahr Schnitt;Vorjahr Spiele\r\nPin Busters;Kurt;m;143,64;99\r\nJolly Rollers;Alma;W;;\r\n\r\n");

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['rows']);
        [$kurt, $alma] = $result['rows'];
        self::assertSame([2, 'Pin Busters', 'Kurt', 'male'], [$kurt->line, $kurt->team, $kurt->name, $kurt->gender]);
        self::assertSame([143.64, 99, 14220], [$kurt->previousAverage, $kurt->previousGames, $kurt->previousPins()]);
        self::assertSame('female', $alma->gender);
        self::assertFalse($alma->hasPreviousSeason());
    }

    public function testCommaSeparatorColumnOrderAndQuotes(): void
    {
        $result = $this->parser->parse("name,geschlecht,team\n\"Müller, Lars\",m,\"The Rolling Pins\"\n");

        self::assertSame([], $result['errors']);
        self::assertSame('Müller, Lars', $result['rows'][0]->name);
        self::assertSame('The Rolling Pins', $result['rows'][0]->team);
    }

    public function testWindows1252AndBomAreConverted(): void
    {
        $windows = mb_convert_encoding("Team;Name;Geschlecht\nGlückspilze;Sören;m\n", 'Windows-1252', 'UTF-8');

        self::assertSame('Sören', $this->parser->parse($windows)['rows'][0]->name);
        self::assertSame('Glückspilze', $this->parser->parse("\xEF\xBB\xBFTeam;Name;Geschlecht\nGlückspilze;Sören;m\n")['rows'][0]->team);
    }

    public function testMissingAndUnknownColumns(): void
    {
        $result = $this->parser->parse("Team;Vorname;Geschlecht\nA;B;m\n");
        $errors = GermanText::all($result['errors']);

        self::assertContains('Unbekannte Spalte „Vorname“. Die erkannten Spaltennamen stehen unter „Aufbau der Datei“.', $errors);
        self::assertContains('Die Spalte „Name“ fehlt.', $errors);
        self::assertContains('The column "Name" is missing.', array_map((new Translator('en'))->message(...), $result['errors']), 'The missing column is named in the visitor\'s language.');
        self::assertSame(['Die Datei ist leer.'], GermanText::all($this->parser->parse("\n  \n")['errors']));
    }

    public function testEnglishColumnNamesAndGenderValues(): void
    {
        $result = $this->parser->parse("Team,Name,Gender,Previous average,Previous games\r\nPin Busters,Kurt,m,143.64,99\r\nJolly Rollers,Alma,f,,\r\n");

        self::assertSame([], $result['errors']);
        self::assertSame(['male', 'female'], [$result['rows'][0]->gender, $result['rows'][1]->gender]);
        self::assertSame(14220, $result['rows'][0]->previousPins());
    }

    public function testColumnNamesOfDifferentLanguagesAndAliasesMayBeMixed(): void
    {
        $result = $this->parser->parse("Mannschaft;Player;SEX;Schnitt Vorjahr;Previous games\nA;Kurt;Herr;150;3\nA;Alma;D;;\nA;Olaf;male;;\nA;Ida;weiblich;;\n");

        self::assertSame([], $result['errors']);
        self::assertSame(['male', 'female', 'male', 'female'], array_map(static fn ($row): string => $row->gender, $result['rows']));
        self::assertSame(450, $result['rows'][0]->previousPins());
    }

    public function testInvalidLinesAreReportedWithLineNumber(): void
    {
        $result = $this->parser->parse(implode("\n", [
            'Team;Name;Geschlecht;Vorjahr Schnitt;Vorjahr Spiele',
            'A;Anna;x;;',
            ';Bernd;m;;',
            'A;Carla;w;150;',
            'A;Dirk;m;350;10',
            'A;Eva;w;150;0',
            'A;Frank;m;150,5;12',
        ]));

        self::assertSame([
            'Zeile 2: Das Geschlecht „x“ ist unbekannt, erwartet wird z. B. „m“ oder „w“.',
            'Zeile 3: Der Teamname fehlt.',
            'Zeile 4: Vorjahr Schnitt und Vorjahr Spiele müssen beide ausgefüllt oder beide leer sein.',
            'Zeile 5: Der Vorjahr-Schnitt „350“ ist keine Zahl zwischen 0 und 300.',
            'Zeile 6: Die Vorjahr-Spiele „0“ sind keine ganze Zahl ab 1.',
        ], GermanText::all($result['errors']));
        self::assertCount(1, $result['rows']);
        self::assertSame(1806, $result['rows'][0]->previousPins(), '150,5 × 12.');
    }
}
