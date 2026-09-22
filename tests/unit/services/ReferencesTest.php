<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\services;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\references\ReferenceInput;
use wmd\banktransferpaymentcodes\references\ReferenceSchemes;
use wmd\banktransferpaymentcodes\services\References;

final class ReferencesTest extends TestCase
{
    /** @return \wmd\banktransferpaymentcodes\references\ReferenceSchemeInterface[] */
    private function schemes(): array
    {
        return array_values((new ReferenceSchemes())->all());
    }

    public function testCandidatesCoverAllSevenBuiltInSchemes(): void
    {
        self::assertCount(7, $this->schemes());
    }

    public function testHrReferenceWithModelPrefixAndDashYieldsTheStoredDigits(): void
    {
        $candidates = References::candidates('HR00 2026-001', $this->schemes());
        self::assertContains('2026001', $candidates);
        self::assertNotContains('', $candidates);
        self::assertSame($candidates, array_values(array_unique($candidates)));
    }

    public function testRfReferenceWithSpacesYieldsTheBodyWithoutPrefixOrCheckDigits(): void
    {
        $candidates = References::candidates('RF18 5390 0754 7034', $this->schemes());
        self::assertContains('539007547034', $candidates);
    }

    public function testBelgianStructuredCommunicationYieldsTheTwelveDigits(): void
    {
        $candidates = References::candidates('+++090/9337/55493+++', $this->schemes());
        self::assertContains('090933755493', $candidates);
        // Every scheme strips the same punctuation here, so every candidate collapses to one.
        self::assertSame(['090933755493'], $candidates);
    }

    public function testSiReferenceWithModelPrefixYieldsTheStoredDigits(): void
    {
        $candidates = References::candidates('SI12 20260016', $this->schemes());
        self::assertContains('20260016', $candidates);
    }

    public function testBlankReferenceYieldsNoCandidates(): void
    {
        self::assertSame([], References::candidates('   ', $this->schemes()));
    }

    public function testAttemptsForAHexOrderNumberStartAtTheIdThenSuffix(): void
    {
        $digits = array_map(
            static fn(ReferenceInput $i): string => $i->digits(),
            References::attempts(new ReferenceInput('915a93b', 2072)),
        );
        // The first attempt already resolves to the bare id, so it is not repeated.
        self::assertSame(
            ['2072', '20721', '20722', '20723', '20724', '20725', '20726', '20727', '20728', '20729'],
            $digits,
        );
    }

    public function testAttemptsForANumericOrderNumberFallBackToTheIdBeforeSuffixing(): void
    {
        $digits = array_map(
            static fn(ReferenceInput $i): string => $i->digits(),
            References::attempts(new ReferenceInput('2026001', 2072)),
        );
        self::assertSame('2026001', $digits[0]);
        self::assertSame('2072', $digits[1]);
        self::assertSame('20721', $digits[2]);
        self::assertSame('20729', end($digits));
        self::assertCount(11, $digits);
        self::assertSame($digits, array_values(array_unique($digits)));
    }

    public function testEveryAttemptKeepsTheOrderId(): void
    {
        foreach (References::attempts(new ReferenceInput('915a93b', 2072)) as $attempt) {
            self::assertSame(2072, $attempt->orderId);
        }
    }
}
