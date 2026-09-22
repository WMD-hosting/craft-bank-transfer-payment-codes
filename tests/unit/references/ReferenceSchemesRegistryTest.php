<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\references;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\references\ReferenceSchemes;

final class ReferenceSchemesRegistryTest extends TestCase
{
    private function account(string $iban, string $scheme = 'auto'): BankAccount
    {
        return BankAccount::fromArray(['key' => 'k', 'holder' => 'H', 'iban' => $iban, 'formats' => ['epc'], 'referenceScheme' => $scheme]);
    }

    public function testAutoPicksNationalSchemeByIbanCountry(): void
    {
        $r = new ReferenceSchemes();
        self::assertSame('HR00', $r->forAccount($this->account('HR3799999990000000001'))->model());
        self::assertSame('SI12', $r->forAccount($this->account('SI56020170014356205'))->model());
        self::assertSame('be', $r->forAccount($this->account('BE72000000001616'))->handle());
        self::assertSame('fi', $r->forAccount($this->account('FI2112345600000785'))->handle());
        self::assertSame('sk', $r->forAccount($this->account('SK7283300000009111111118'))->handle());
        self::assertSame('rf', $r->forAccount($this->account('DE89370400440532013000'))->handle());
    }

    public function testExplicitOverride(): void
    {
        $r = new ReferenceSchemes();
        self::assertSame('HR01', $r->forAccount($this->account('HR3799999990000000001', 'hr01'))->model());
        self::assertSame('rf', $r->forAccount($this->account('HR3799999990000000001', 'rf'))->handle());
    }

    public function testAllHandles(): void
    {
        self::assertSame(['rf', 'hr00', 'hr01', 'si12', 'be', 'fi', 'sk'], array_keys((new ReferenceSchemes())->all()));
    }
}
