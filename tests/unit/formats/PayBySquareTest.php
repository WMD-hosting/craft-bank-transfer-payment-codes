<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\formats;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\formats\PayBySquare;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\models\PaymentDetails;
use wmd\banktransferpaymentcodes\tests\Support\Lzma1Decoder;

final class PayBySquareTest extends TestCase
{
    private function details(): PaymentDetails
    {
        $acc = BankAccount::fromArray(['key' => 'sk', 'holder' => 'Acme s.r.o.', 'iban' => 'SK7283300000009111111118', 'bic' => 'FIOZSKBAXXX', 'formats' => ['paybysquare'], 'street' => 'Hlavna 1', 'postcode' => '811 01', 'city' => 'Bratislava']);
        return new PaymentDetails($acc, 1234.56, 'EUR', '2026001', '', false, 'Invoice FA20260103', '2026001');
    }

    public function testDataStringMatchesSpecVector(): void
    {
        $tsv = "\t1\t1\t1234.56\tEUR\t\t2026001\t\t\t\tInvoice FA20260103\t1\tSK7283300000009111111118\tFIOZSKBAXXX\t0\t0\tAcme s.r.o.\tHlavna 1\t811 01 Bratislava";
        self::assertSame($tsv, PayBySquare::dataString($this->details()));
    }

    public function testPayloadIsBase32HexAndDecodesBack(): void
    {
        $payload = (new PayBySquare())->payload($this->details());
        self::assertMatchesRegularExpression('/^[0-9A-V]+$/', $payload);
        self::assertSame(PayBySquare::dataString($this->details()), Lzma1Decoder::decode($payload));
    }

    public function testSvgCarriesTheLogoFrame(): void
    {
        $f = new PayBySquare();
        $svg = $f->svg($f->payload($this->details()), 300);
        self::assertStringContainsString('PAY by square', $svg);
        $xml = simplexml_load_string($svg);
        self::assertNotFalse($xml);
    }

    public function testRequiresBeneficiaryName(): void
    {
        $acc = BankAccount::fromArray(['key' => 'sk', 'holder' => '', 'iban' => 'SK7283300000009111111118', 'formats' => ['paybysquare']]);
        self::assertNotNull((new PayBySquare())->supports(new PaymentDetails($acc, 1.0, 'EUR', '1', '', false, 'p', '1')));
    }
}
