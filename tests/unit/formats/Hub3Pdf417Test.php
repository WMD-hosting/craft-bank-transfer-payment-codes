<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\formats;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\formats\Hub3Pdf417;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\models\PaymentDetails;

final class Hub3Pdf417Test extends TestCase
{
    private function details(array $account = [], float $amount = 24.60, string $reference = '2026001', string $model = 'HR00'): PaymentDetails
    {
        $acc = BankAccount::fromArray($account + [
            'key' => 'main', 'holder' => 'HENA COM d.o.o.', 'iban' => 'HR1210010051863000160', 'formats' => ['hub3'],
            'street' => 'Ulica grada Vukovara 269d', 'postcode' => '10000', 'city' => 'Zagreb',
        ]);
        return new PaymentDetails($acc, $amount, 'EUR', $reference, $model, true, 'Narudžba 2026001', '2026001',
            payerName: 'Ivan Đivić', payerStreet: 'Augusta Harambašića 3', payerCity: 'Osijek', payerPostcode: '31000');
    }

    public function testPayloadMatchesHubV6Layout(): void
    {
        $expected = implode("\n", [
            'HRVHUB30', 'EUR', '000000000002460',
            'Ivan Đivić', 'Augusta Harambašića 3', '31000 Osijek',
            'HENA COM d.o.o.', 'Ulica grada Vukovara 269d', '10000 Zagreb',
            'HR1210010051863000160', 'HR00', '2026001', 'COST', 'Narudžba 2026001',
        ]);
        self::assertSame($expected, (new Hub3Pdf417())->payload($this->details()));
    }

    public function testPayeeNameTruncatedTo25Characters(): void
    {
        $payload = (new Hub3Pdf417())->payload($this->details(['holder' => 'Knjižara Hena com društvo s ograničenom odgovornošću']));
        self::assertSame('Knjižara Hena com društvo', explode("\n", $payload)[6]);
    }

    public function testDisallowedCharactersAreReplaced(): void
    {
        $payload = (new Hub3Pdf417())->payload($this->details(['holder' => 'Ærø & Sons "Ltd"']));
        self::assertSame('AEro   Sons  Ltd', explode("\n", $payload)[6]);
    }

    public function testAmountIsFifteenDigitCents(): void
    {
        self::assertSame('000000012345678', explode("\n", (new Hub3Pdf417())->payload($this->details(amount: 123456.78)))[2]);
    }

    public function testRequiresHrIbanAndEur(): void
    {
        $f = new Hub3Pdf417();
        self::assertNotNull($f->supports($this->details(['iban' => 'DE89370400440532013000'])));
        self::assertNull($f->supports($this->details()));
    }

    public function testRendersPdf417SvgAndPng(): void
    {
        $f = new Hub3Pdf417();
        $payload = $f->payload($this->details());
        self::assertStringContainsString('<svg', $f->svg($payload, 400));
        self::assertSame("\x89PNG", substr($f->png($payload, 400), 0, 4));
    }
}
