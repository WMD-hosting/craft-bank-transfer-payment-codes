<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\references;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\references\BeStructured;
use wmd\banktransferpaymentcodes\references\FiEeReference;
use wmd\banktransferpaymentcodes\references\HrModel;
use wmd\banktransferpaymentcodes\references\ReferenceInput;
use wmd\banktransferpaymentcodes\references\Rf;
use wmd\banktransferpaymentcodes\references\SiModel;
use wmd\banktransferpaymentcodes\references\SkSymbols;

final class ReferenceSchemesTest extends TestCase
{
    private function input(string $number, int $id = 42): ReferenceInput
    {
        return new ReferenceInput($number, $id);
    }

    public function testRfMatchesIsoExamples(): void
    {
        $rf = new Rf();
        self::assertSame('RF18539007547034', $rf->generate($this->input('539007547034')));
        self::assertSame('RF712348231', $rf->generate($this->input('2348231')));
        self::assertSame('RF281000123', $rf->generate($this->input('1000123')));
        self::assertTrue($rf->validate('RF18539007547034'));
        self::assertTrue($rf->validate('RF18 5390 0754 7034'));
        self::assertFalse($rf->validate('RF19539007547034'));
        self::assertSame('539007547034', $rf->normalise('RF18 5390 0754 7034'));
    }

    public function testHr00HasNoCheckDigit(): void
    {
        $hr = new HrModel(false);
        self::assertSame('2026001', $hr->generate($this->input('2026001')));
        self::assertSame('HR00', $hr->model());
        self::assertTrue($hr->validate('2026001'));
        self::assertFalse($hr->validate('2026001-1-1-1')); // max three parts
        self::assertSame('2026001', $hr->normalise('HR00 2026-001'));
    }

    public function testHr01UsesIso7064Mod11Ten(): void
    {
        $hr = new HrModel(true);
        self::assertSame('HR01', $hr->model());
        self::assertSame('07945', $hr->generate($this->input('0794')));    // ISO 7064 example
        self::assertSame('12340', $hr->generate($this->input('1234')));
        self::assertSame('20260016', $hr->generate($this->input('2026001')));
        self::assertTrue($hr->validate('07945'));
        self::assertFalse($hr->validate('07944'));
    }

    public function testSi12(): void
    {
        $si = new SiModel();
        self::assertSame('SI12', $si->model());
        self::assertSame('20260016', $si->generate($this->input('2026001')));
        self::assertSame('123456789016', $si->generate($this->input('12345678901')));
        self::assertTrue($si->validate('20260016'));
        self::assertFalse($si->validate('20260017'));
    }

    public function testBelgianStructuredCommunication(): void
    {
        $be = new BeStructured();
        self::assertSame('+++090/9337/55493+++', $be->generate($this->input('909337554')));
        self::assertSame('+++000/0000/09797+++', $be->generate($this->input('97')));   // remainder 0 → 97
        self::assertSame('+++000/0001/00030+++', $be->generate($this->input('1000')));
        self::assertTrue($be->validate('+++090/9337/55493+++'));
        self::assertTrue($be->validate('090933755493'));
        self::assertFalse($be->validate('+++090/9337/55494+++'));
        self::assertSame('090933755493', $be->normalise('+++090/9337/55493+++'));
    }

    public function testFinnishReference(): void
    {
        $fi = new FiEeReference();
        self::assertSame('12328', $fi->generate($this->input('1232')));
        self::assertSame('123453', $fi->generate($this->input('12345')));
        self::assertSame('20260011', $fi->generate($this->input('2026001')));
        self::assertTrue($fi->validate('1232 8'));
        self::assertFalse($fi->validate('12329'));
    }

    public function testFinnishReferenceIsPaddedToTheFourCharacterMinimum(): void
    {
        $fi = new FiEeReference();
        self::assertSame('0071', $fi->generate($this->input('7')));
        self::assertSame('0424', $fi->generate($this->input('abc', 42)));
        self::assertTrue($fi->validate('0071'));
    }

    public function testSlovakVariableSymbolIsDigitsMaxTen(): void
    {
        $sk = new SkSymbols();
        self::assertSame('2026001', $sk->generate($this->input('2026001')));
        self::assertSame('1234567890', $sk->generate($this->input('123456789012')));  // keeps the first 10 digits
        self::assertTrue($sk->validate('2026001'));
        self::assertFalse($sk->validate('20A6'));
    }

    public function testFallsBackToOrderIdWhenNumberHasNoDigits(): void
    {
        self::assertSame((new Rf())->generate($this->input('42')), (new Rf())->generate($this->input('abc', 42)));
        self::assertSame('42', (new HrModel(false))->generate($this->input('abc', 42)));
    }

    public function testHexOrderNumberUsesTheOrderIdRatherThanItsDigits(): void
    {
        // '915a93b' would collapse to '91593' under a digit filter, and so would
        // '9159b3' and '915b93' — different orders, one reference. The ID is unique.
        $input = $this->input('915a93b', 2072);
        self::assertSame('2072', $input->digits());
        self::assertSame('2072', $input->fallbackDigits());
        self::assertSame('2072', (new HrModel(false))->generate($input));
        self::assertSame((new Rf())->generate($this->input('2072')), (new Rf())->generate($input));
    }

    public function testAllDigitOrderNumberIsUsedAsIs(): void
    {
        $input = $this->input('2026001', 2072);
        self::assertSame('2026001', $input->digits());
        self::assertSame('2072', $input->fallbackDigits());
    }
}
