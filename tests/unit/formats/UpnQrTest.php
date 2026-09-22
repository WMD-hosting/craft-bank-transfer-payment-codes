<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\formats;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\formats\UpnQr;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\models\PaymentDetails;

final class UpnQrTest extends TestCase
{
    private function details(): PaymentDetails
    {
        $acc = BankAccount::fromArray(['key' => 'si', 'holder' => 'Podjetje d.o.o.', 'iban' => 'SI56020360253863406', 'formats' => ['upn'], 'street' => 'Neka ulica 5', 'postcode' => '1000', 'city' => 'Ljubljana']);
        return new PaymentDetails($acc, 55.59, 'EUR', '20260016', 'SI12', true, 'Naročilo 2026001', '2026001',
            payerName: 'Janez Novak', payerStreet: 'Lepa ulica 33', payerCity: 'Koper', payerPostcode: '6000');
    }

    public function testPayloadHasNineteenFieldsAndLengthChecksum(): void
    {
        $payload = (new UpnQr())->payload($this->details());
        $lines = explode("\n", $payload);
        self::assertSame('UPNQR', $lines[0]);
        self::assertSame('', $lines[1]);                       // payer IBAN
        self::assertSame('Janez Novak', $lines[5]);
        self::assertSame('00000005559', $lines[8]);            // amount, 11 digits
        self::assertSame('OTHR', $lines[11]);                  // purpose code
        self::assertSame('Naročilo 2026001', $lines[12]);
        self::assertSame('SI56020360253863406', $lines[14]);
        self::assertSame('SI1220260016', $lines[15]);          // model + reference, no space
        self::assertSame('Podjetje d.o.o.', $lines[16]);
        self::assertCount(20, $lines);                         // 19 fields + checksum line
        $body = implode("\n", array_slice($lines, 0, 19)) . "\n";
        self::assertSame(str_pad((string)strlen(iconv('UTF-8', 'ISO-8859-2', $body)), 3, '0', STR_PAD_LEFT), $lines[19]);
    }

    public function testRendersVersion15WithIso88592Eci(): void
    {
        $f = new UpnQr();
        $payload = $f->payload($this->details());
        $qr = \wmd\banktransferpaymentcodes\rendering\QrRenderer::encode($payload, 'ISO-8859-2', true, 15);
        self::assertSame(15, $qr->getVersion()->getVersionNumber());
        self::assertSame("\x89PNG", substr($f->png($payload, 300), 0, 4));
    }

    public function testRequiresSlovenianIbanAndSiOrRfReference(): void
    {
        $f = new UpnQr();
        $acc = BankAccount::fromArray(['key' => 'x', 'holder' => 'H', 'iban' => 'HR3799999990000000001', 'formats' => ['upn']]);
        self::assertNotNull($f->supports(new PaymentDetails($acc, 1.0, 'EUR', '1', 'HR00', true, 'p', '1')));
    }
}
