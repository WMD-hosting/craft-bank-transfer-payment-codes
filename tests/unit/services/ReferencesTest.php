<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\services;

use PHPUnit\Framework\TestCase;
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
}
