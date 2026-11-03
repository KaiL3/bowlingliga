<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Import;

use Bowlingliga\Application\Import\PlayerCsvParser;
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
        $this->parser = new PlayerCsvParser();
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
        $errors = GermanText::all($this->parser->parse("Team;Spielername;Geschlecht\nA;B;m\n")['errors']);

        self::assertContains('Unbekannte Spalte „Spielername“. Erlaubt sind: Team, Name, Geschlecht, Vorjahr Schnitt, Vorjahr Spiele.', $errors);
        self::assertContains('Die Spalte „Name“ fehlt.', $errors);
        self::assertSame(['Die Datei ist leer.'], GermanText::all($this->parser->parse("\n  \n")['errors']));
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
            'Zeile 2: Das Geschlecht muss „m“ oder „w“ sein, nicht „x“.',
            'Zeile 3: Der Teamname fehlt.',
            'Zeile 4: Vorjahr Schnitt und Vorjahr Spiele müssen beide ausgefüllt oder beide leer sein.',
            'Zeile 5: Der Vorjahr-Schnitt „350“ ist keine Zahl zwischen 0 und 300.',
            'Zeile 6: Die Vorjahr-Spiele „0“ sind keine ganze Zahl ab 1.',
        ], GermanText::all($result['errors']));
        self::assertCount(1, $result['rows']);
        self::assertSame(1806, $result['rows'][0]->previousPins(), '150,5 × 12.');
    }
}
