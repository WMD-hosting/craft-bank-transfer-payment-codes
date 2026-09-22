<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\services;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\formats\Formats;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\models\PaymentDetails;
use wmd\banktransferpaymentcodes\services\Codes;

final class CodesTest extends TestCase
{
    private function details(string $iban, array $formats, string $currency = 'EUR', string $model = '', string $ref = 'RF281000123'): PaymentDetails
    {
        $acc = BankAccount::fromArray(['key' => 'k', 'holder' => 'Holder d.o.o.', 'iban' => $iban, 'formats' => $formats, 'street' => 'S 1', 'postcode' => '10000', 'city' => 'C']);
        return new PaymentDetails($acc, 10.0, $currency, $ref, $model, true, 'Order 1', '1');
    }

    public function testCroatianAccountGetsHub3FirstThenEpc(): void
    {
        $codes = (new Codes(new Formats(), 300))->forDetails($this->details('HR3799999990000000001', ['epc', 'hub3'], model: 'HR00', ref: '1000123'));
        self::assertSame(['hub3', 'epc'], array_map(fn($c) => $c->handle(), $codes));
    }

    public function testUnsupportedFormatsAreSkippedInProduction(): void
    {
        $codes = (new Codes(new Formats(), 300))->forDetails($this->details('HR3799999990000000001', ['epc', 'hub3'], currency: 'USD', model: 'HR00', ref: '1'));
        self::assertSame([], $codes);
    }

    public function testUnsupportedFormatThrowsInStrictMode(): void
    {
        $this->expectException(\wmd\banktransferpaymentcodes\formats\FormatException::class);
        (new Codes(new Formats(), 300))->forDetails($this->details('DE89370400440532013000', ['hub3']), strict: true);
    }

    public function testUnregisteredFormatHandleIsSkippedInLenientMode(): void
    {
        $codes = (new Codes(new Formats(), 300))->forDetails($this->details('HR3799999990000000001', ['bogus', 'hub3'], model: 'HR00', ref: '1000123'));
        self::assertSame(['hub3'], array_map(fn($c) => $c->handle(), $codes));
    }

    public function testLenientSkipsAreReportedToTheInjectedLogger(): void
    {
        $lines = [];
        $log = function(string $message) use (&$lines): void {
            $lines[] = $message;
        };
        (new Codes(new Formats(), 300, null, null, $log))
            ->forDetails($this->details('HR3799999990000000001', ['bogus', 'upn', 'hub3'], model: 'HR00', ref: '1000123'));
        self::assertSame('Format bogus skipped: not a registered format', $lines[0]);
        self::assertStringStartsWith('Format upn skipped: ', $lines[1]);
        self::assertCount(2, $lines);
    }

    public function testUnregisteredFormatHandleThrowsInStrictMode(): void
    {
        $this->expectException(\wmd\banktransferpaymentcodes\formats\FormatException::class);
        (new Codes(new Formats(), 300))->forDetails($this->details('HR3799999990000000001', ['bogus'], model: 'HR00', ref: '1'), strict: true);
    }

    public function testPaymentCodeRenders(): void
    {
        $code = (new Codes(new Formats(), 200))->forDetails($this->details('DE89370400440532013000', ['epc']))[0];
        self::assertSame('EPC QR (SEPA)', $code->label());
        self::assertStringContainsString('<svg', $code->svg());
        self::assertStringStartsWith('data:image/png;base64,', $code->pngDataUri());
        self::assertSame($code->pngDataUri(), $code->emailSrc());
    }
}
