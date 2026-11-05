<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Import;

use Bowlingliga\Application\Import\ImportVocabulary;
use Bowlingliga\I18n\CatalogImportVocabulary;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the column names and gender values of the player import (R6).
 */
final class ImportVocabularyTest extends TestCase
{
    public function testTheCatalogsGiveAConsistentVocabulary(): void
    {
        // Building it checks that no word of any catalog means two things.
        $vocabulary = CatalogImportVocabulary::all();

        self::assertSame('gender', $vocabulary->field('  geschlecht '));
        self::assertSame('previous_average', $vocabulary->field('Previous   Average'));
        self::assertNull($vocabulary->field('Vorname'));
        self::assertSame('female', $vocabulary->gender('W'));
        self::assertNull($vocabulary->gender('x'));
    }

    public function testAWordWithTwoMeaningsIsRejected(): void
    {
        $this->expectException(LogicException::class);

        new ImportVocabulary(['team' => ['Team'], 'name' => ['Name', 'Team'], 'gender' => ['Gender']], ['male' => ['m'], 'female' => ['f']]);
    }

    public function testAGenderValueWithTwoMeaningsIsRejected(): void
    {
        $this->expectException(LogicException::class);

        new ImportVocabulary(['team' => ['Team'], 'name' => ['Name'], 'gender' => ['Gender']], ['male' => ['m'], 'female' => ['f', 'M']]);
    }

    public function testEveryRequiredFieldNeedsAColumnName(): void
    {
        $this->expectException(LogicException::class);

        new ImportVocabulary(['team' => ['Team'], 'name' => ['Name']], ['male' => ['m'], 'female' => ['f']]);
    }
}
