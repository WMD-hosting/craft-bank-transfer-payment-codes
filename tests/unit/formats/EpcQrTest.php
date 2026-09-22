<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\formats;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\formats\EpcQr;
use wmd\banktransferpaymentcodes\formats\FormatException;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\models\PaymentDetails;

final class EpcQrTest extends TestCase
{
    private function details(array $account = [], float $amount = 12.34, string $currency = 'EUR', string $reference = 'RF281000123', bool $structured = true, string $purpose = 'Order 1000123'): PaymentDetails
    {
        $acc = BankAccount::fromArray($account + ['key' => 'main', 'holder' => 'HENA COM d.o.o.', 'iban' => 'HR3799999990000000001', 'formats' => ['epc']]);
        return new PaymentDetails($acc, $amount, $currency, $reference, '', $structured, $purpose, '1000123');
    }

    public function testPayloadV002WithoutBicAndStructuredReference(): void
    {
        $expected = "BCD\n002\n1\nSCT\n\nHENA COM d.o.o.\nHR3799999990000000001\nEUR12.34\n\nRF281000123";
        self::assertSame($expected, (new EpcQr())->payload($this->details()));
    }

    public function testUnstructuredRemittanceGoesToLineElevenWithThePurposeFirst(): void
    {
        $expected = "BCD\n002\n1\nSCT\n\nHENA COM d.o.o.\nHR3799999990000000001\nEUR12.34\n\n\nOrder 1000123 1000123";
        self::assertSame($expected, (new EpcQr())->payload($this->details(reference: '1000123', structured: false)));
    }

    public function testUnstructuredRemittanceIsTrimmedTo140Characters(): void
    {
        $payload = (new EpcQr())->payload($this->details(reference: '1000123', structured: false, purpose: str_repeat('a', 200)));
        self::assertSame(140, mb_strlen(explode("\n", $payload)[10]));
    }

    public function testLongPurposeIsShrunkButTheReferenceSurvives(): void
    {
        // 120 emoji fit inside the 140-character field but blow the 331-byte
        // budget at 4 bytes each, so the purpose is shrunk from the right.
        $payload = (new EpcQr())->payload($this->details(
            reference: '1000123',
            structured: false,
            purpose: str_repeat("\u{1F600}", 120),
        ));
        $line = explode("\n", $payload)[10];
        self::assertLessThanOrEqual(331, strlen($payload));
        self::assertStringEndsWith(' 1000123', $line);
        self::assertStringStartsWith("\u{1F600}", $line);
        self::assertLessThan(120, mb_strlen($line) - mb_strlen(' 1000123'));
        self::assertSame('HENA COM d.o.o.', explode("\n", $payload)[5]);
    }

    public function testAVeryLongPurposeIsCappedAt140CharactersWithTheReferenceIntact(): void
    {
        $payload = (new EpcQr())->payload($this->details(
            reference: '1000123',
            structured: false,
            purpose: str_repeat('a', 400),
        ));
        $line = explode("\n", $payload)[10];
        self::assertSame(140, mb_strlen($line));
        self::assertStringEndsWith(' 1000123', $line);
    }

    public function testReferenceSurvivesEvenWhenTheBeneficiaryNameIsHuge(): void
    {
        $payload = (new EpcQr())->payload($this->details(
            ['holder' => str_repeat("\u{1F600}", 80)],
            reference: '1000123',
            structured: false,
            purpose: 'Order 1000123',
        ));
        self::assertLessThanOrEqual(331, strlen($payload));
        self::assertStringEndsWith('1000123', $payload);
    }

    public function testStructuredReferenceOver35CharactersIsRefused(): void
    {
        $f = new EpcQr();
        $d = $this->details(reference: str_repeat('9', 36));
        self::assertNotNull($f->supports($d));
        $this->expectException(FormatException::class);
        $f->payload($d);
    }

    public function testBicIncludedForNonEeaIbanWithVersion001(): void
    {
        $d = $this->details(['iban' => 'CH9300762011623852957', 'bic' => 'UBSWCHZH80A']);
        $payload = (new EpcQr())->payload($d);
        self::assertStringStartsWith("BCD\n001\n1\nSCT\nUBSWCHZH80A\n", $payload);
    }

    public function testForceBicSetting(): void
    {
        $d = $this->details(['bic' => 'ZABAHR2X']);
        $payload = (new EpcQr(forceBic: true))->payload($d);
        self::assertStringStartsWith("BCD\n001\n1\nSCT\nZABAHR2X\n", $payload);
    }

    public function testAmountFormatting(): void
    {
        self::assertStringContainsString("\nEUR1234.5\n", (new EpcQr())->payload($this->details(amount: 1234.50)));
        self::assertStringContainsString("\nEUR0.01\n", (new EpcQr())->payload($this->details(amount: 0.01)));
    }

    public function testRejectsNonEurAndOutOfRange(): void
    {
        $f = new EpcQr();
        self::assertNotNull($f->supports($this->details(currency: 'USD')));
        self::assertNotNull($f->supports($this->details(amount: 0.0)));
        self::assertNotNull($f->supports($this->details(amount: 1_000_000_000.0)));
        self::assertNull($f->supports($this->details()));
    }

    public function testNameShrunkBelowMaxCharsForEmojiWith4ByteUtf8(): void
    {
        // 80 emoji characters (4 bytes each = 320 bytes for name alone) + IBAN + amount + RF reference exceeds 331 bytes.
        // Name must be shrunk below 70 characters to fit within 331 bytes.
        $long = str_repeat("\u{1F600}", 80);
        $payload = (new EpcQr())->payload($this->details(['holder' => $long], reference: 'RF281000123', structured: true));
        $lines = explode("\n", $payload);
        $nameLength = mb_strlen($lines[5]);
        self::assertLessThan(70, $nameLength);
        self::assertLessThanOrEqual(331, strlen($payload));
        self::assertTrue(mb_check_encoding($lines[5], 'UTF-8'));
    }

    public function testNameTruncatedTo70CharsForTwoByteUtf8(): void
    {
        $long = str_repeat('Đ', 80);
        $payload = (new EpcQr())->payload($this->details(['holder' => $long]));
        $lines = explode("\n", $payload);
        self::assertSame(70, mb_strlen($lines[5]));
        self::assertLessThanOrEqual(331, strlen($payload));
    }

    public function testNoTrailingNewlineAndNoEmptyTrailingFields(): void
    {
        $payload = (new EpcQr())->payload($this->details());
        self::assertStringEndsNotWith("\n", $payload);
    }

    public function testRendersSvgAndPngWithoutEci(): void
    {
        $f = new EpcQr();
        $payload = $f->payload($this->details());
        $svg = $f->svg($payload, 300);
        self::assertStringStartsWith('<?xml', $svg);
        self::assertStringContainsString('<svg', $svg);
        $png = $f->png($payload, 300);
        self::assertSame("\x89PNG", substr($png, 0, 4));
    }
}
