<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\helpers;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\helpers\Iban;

final class IbanTest extends TestCase
{
    public function testValidIbans(): void
    {
        self::assertTrue(Iban::isValid('HR3799999990000000001'));
        self::assertTrue(Iban::isValid('hr12 1001 0051 8630 0016 0'));
        self::assertTrue(Iban::isValid('DE89370400440532013000'));
        self::assertTrue(Iban::isValid('SI56020170014356205'));
        self::assertTrue(Iban::isValid('BE72000000001616'));
        self::assertTrue(Iban::isValid('SK7283300000009111111118'));
    }

    public function testInvalidIbans(): void
    {
        self::assertFalse(Iban::isValid('HR1210010051863000161'));
        self::assertFalse(Iban::isValid('DE8937040044053201300'));
        self::assertFalse(Iban::isValid(''));
        self::assertFalse(Iban::isValid('1234'));
    }

    public function testCountryAndEea(): void
    {
        self::assertSame('HR', Iban::country('hr12 1001 0051 8630 0016 0'));
        self::assertTrue(Iban::isEea('HR'));
        self::assertTrue(Iban::isEea('NO'));
        self::assertFalse(Iban::isEea('CH'));
        self::assertFalse(Iban::isEea('GB'));
    }
}
