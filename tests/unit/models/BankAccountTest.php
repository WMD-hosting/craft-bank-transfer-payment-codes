<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\models;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\models\BankAccount;

final class BankAccountTest extends TestCase
{
    public function testFromArrayNormalisesIbanAndCountry(): void
    {
        $a = BankAccount::fromArray(['key' => 'main', 'holder' => 'HENA COM d.o.o.', 'iban' => 'hr37 9999 9990 0000 0000 1', 'formats' => ['hub3', 'epc']]);
        self::assertSame('HR3799999990000000001', $a->iban);
        self::assertSame('HR', $a->country());
        self::assertSame('EUR', $a->currency);
        self::assertNull($a->purposeTemplate);
    }

    public function testValidationErrors(): void
    {
        $bad = BankAccount::fromArray(['key' => '', 'holder' => '', 'iban' => 'HR1210010051863000161', 'bic' => '12', 'formats' => []]);
        $errors = $bad->validationErrors();
        self::assertArrayHasKey('key', $errors);
        self::assertArrayHasKey('holder', $errors);
        self::assertArrayHasKey('iban', $errors);
        self::assertArrayHasKey('bic', $errors);
        self::assertArrayHasKey('formats', $errors);
    }

    public function testNonEeaIbanRequiresBic(): void
    {
        $ch = BankAccount::fromArray(['key' => 'ch', 'holder' => 'X AG', 'iban' => 'CH9300762011623852957', 'formats' => ['epc']]);
        self::assertArrayHasKey('bic', $ch->validationErrors());
    }

    public function testSchemeCountryMismatch(): void
    {
        $a = BankAccount::fromArray(['key' => 'de', 'holder' => 'X GmbH', 'iban' => 'DE89370400440532013000', 'formats' => ['epc'], 'referenceScheme' => 'hr00']);
        self::assertArrayHasKey('referenceScheme', $a->validationErrors());
    }

    public function testUnknownFormatHandleIsRejected(): void
    {
        $a = BankAccount::fromArray(['key' => 'main', 'holder' => 'HENA COM d.o.o.', 'iban' => 'HR3799999990000000001', 'formats' => ['epc', 'bogus']]);
        self::assertArrayHasKey('formats', $a->validationErrors());
    }
}
