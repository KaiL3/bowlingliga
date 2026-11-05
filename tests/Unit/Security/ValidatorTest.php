<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the server-side input validation (task 19/21.5).
 *
 * Validates: R6.2, R6.3, R7.2, R8.2, R22.4
 *
 * Checks the range and format rules of the {@see Validator}: name length
 * (R6.2/R7.2), gender (R6.3), net 0–300 (R22.4), even lane count ≥ 2 (R8.2)
 * and amounts of money ≥ 0.00 (R8.2). The exception names the field concerned
 * in each case.
 */
final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function testValidNameIsReturnedTrimmed(): void
    {
        self::assertSame('Käte Groß', $this->validator->checkName('  Käte Groß  '));
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->checkName('   ');
    }

    public function testTooLongNameIsRejected(): void
    {
        try {
            $this->validator->checkName(str_repeat('a', 101));
            self::fail('A name of 101 characters should have been rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('field.name', $exception->field);
        }
    }

    public function testNameWithExactly100CharactersIsValid(): void
    {
        $name = str_repeat('a', 100);
        self::assertSame($name, $this->validator->checkName($name));
    }

    #[DataProvider('validGenders')]
    public function testValidGenderIsNormalised(string $input, string $expected): void
    {
        self::assertSame($expected, $this->validator->checkGender($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validGenders(): array
    {
        return [
            'male'                  => ['male', 'male'],
            'female'                => ['female', 'female'],
            'male mit Space'        => ['  male ', 'male'],
            'gemischte Groß-/Klein' => ['Female', 'female'],
        ];
    }

    public function testInvalidGenderIsRejected(): void
    {
        try {
            $this->validator->checkGender('divers');
            self::fail('An invalid gender should have been rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('field.gender', $exception->field);
        }
    }

    #[DataProvider('validNetValues')]
    public function testValidNetIsAccepted(int $net): void
    {
        self::assertSame($net, $this->validator->checkNet($net));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function validNetValues(): array
    {
        return ['untere Grenze' => [0], 'middle' => [150], 'obere Grenze' => [300]];
    }

    #[DataProvider('invalidNetValues')]
    public function testInvalidNetIsRejected(int $net): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->checkNet($net);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function invalidNetValues(): array
    {
        return ['negative' => [-1], 'ueber 300' => [301]];
    }

    public function testEvenLaneCountFromTwoIsValid(): void
    {
        self::assertSame(2, $this->validator->checkLaneCount(2));
        self::assertSame(8, $this->validator->checkLaneCount(8));
    }

    #[DataProvider('invalidLaneCounts')]
    public function testInvalidLaneCountIsRejected(int $laneCount): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->checkLaneCount($laneCount);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function invalidLaneCounts(): array
    {
        return ['null' => [0], 'odd' => [3], 'kleiner als 2' => [1]];
    }

    public function testMoneyAmountIsNormalisedToTwoDecimals(): void
    {
        self::assertSame(12.35, $this->validator->checkMoneyAmount(12.345));
        self::assertSame(0.0, $this->validator->checkMoneyAmount(0.0));
    }

    public function testNegativeMoneyAmountIsRejected(): void
    {
        try {
            $this->validator->checkMoneyAmount(-0.01, 'field.entry_fee');
            self::fail('A negative amount should have been rejected.');
        } catch (ValidationException $exception) {
            self::assertSame('field.entry_fee', $exception->field);
        }
    }

    public function testCmsTextWithinLengthIsValid(): void
    {
        self::assertSame('Hallo', $this->validator->checkCmsText('Hallo', 10));
    }

    public function testTooLongCmsTextIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->checkCmsText('abcdef', 5);
    }

    // -------------------------------------------------------------------------
    // Vorsaison-Bestandsdaten (Task 22.3; R29.4, R29.6, R29.7)
    // -------------------------------------------------------------------------

    #[DataProvider('validPreviousSeasonRecords')]
    public function testValidPreviousSeasonRecordDataIsAccepted(int $numberOfGames, int $netPinsTotal): void
    {
        $this->validator->checkPreviousSeasonRecord($numberOfGames, $netPinsTotal);

        // No throw means: valid (R29.3, R29.5, R29.7).
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function validPreviousSeasonRecords(): array
    {
        return [
            'ohne Spiele (kein Durchschnitt ableitbar)' => [0, 0],
            'null Spiele mit vorhandenen Pins'          => [0, 500],
            'Durchschnitt an unterer Grenze 0'          => [3, 0],
            'plausibler Durchschnitt'                   => [10, 1500],
            'Durchschnitt an oberer Grenze 300'         => [4, 1200],
        ];
    }

    public function testNegativeNumberOfPlayedGamesIsRejected(): void
    {
        try {
            $this->validator->checkPreviousSeasonRecord(-1, 0);
            self::fail('A negative number of games played should have been rejected (R29.4).');
        } catch (ValidationException $exception) {
            self::assertSame('field.previous_games', $exception->field);
        }
    }

    public function testNegativeNetPinsTotalIsRejected(): void
    {
        try {
            $this->validator->checkPreviousSeasonRecord(5, -1);
            self::fail('A negative sum of net pins should have been rejected (R29.6).');
        } catch (ValidationException $exception) {
            self::assertSame('field.previous_pins', $exception->field);
        }
    }

    public function testImplausibleDerivableAverageIsRejected(): void
    {
        try {
            // 4 games, 1204 pins -> average 301.0 > 300 (R29.7).
            $this->validator->checkPreviousSeasonRecord(4, 1204);
            self::fail('A derivable average above 300 should have been rejected (R29.7).');
        } catch (ValidationException $exception) {
            self::assertSame('field.previous_record', $exception->field);
        }
    }

    public function testAveragePlausibilityIsNotCheckedForZeroGames(): void
    {
        // With 0 games no average can be derived; a high pin total stays
        // allowed without a plausibility check (R29.7 only applies for count > 0).
        $this->validator->checkPreviousSeasonRecord(0, 100000);
        $this->addToAssertionCount(1);
    }
}
