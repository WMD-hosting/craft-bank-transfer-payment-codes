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
            'key' => 'main', 'holder' => 'HENA COM d.o.o.', 'iban' => 'HR3799999990000000001', 'formats' => ['hub3'],
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
            'HR3799999990000000001', 'HR00', '2026001', 'COST', 'Narudžba 2026001',
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

    /**
     * A phone has to read the barcode off a page or PDF shown on screen (T 996399): a symbol about 190 modules
     * across, about twice as wide as tall, with every bar in one SVG path. Touching rects anti-alias into grey
     * seams that alone made the code unreadable at sizes where the path still reads.
     */
    public function testSvgIsOneCoarsePath(): void
    {
        $f = new Hub3Pdf417();
        $payload = $f->payload($this->details());
        $svg = $f->svg($payload, 300);

        self::assertSame(1, substr_count($svg, '<path'));
        self::assertSame(1, substr_count($svg, '<rect'), 'only the white background is a rect');
        self::assertStringContainsString('<desc>' . htmlspecialchars($payload, ENT_XML1) . '</desc>', $svg);

        self::assertSame(1, preg_match('/<path[^>]* d="([^"]+)"/', $svg, $path));
        self::assertGreaterThan(0, preg_match_all('/M([\d.]+) ([\d.]+)h([\d.]+)v([\d.]+)h-\3z/', $path[1], $runs, PREG_SET_ORDER));
        self::assertSame($path[1], implode('', array_column($runs, 0)), 'the path is nothing but closed runs');

        $module = min(array_map(static fn(array $r): float => (float)$r[3], $runs));
        $left = min(array_map(static fn(array $r): float => (float)$r[1], $runs));
        $right = max(array_map(static fn(array $r): float => (float)$r[1] + (float)$r[3], $runs));
        $top = min(array_map(static fn(array $r): float => (float)$r[2], $runs));
        $bottom = max(array_map(static fn(array $r): float => (float)$r[2] + (float)$r[4], $runs));

        $columns = ($right - $left) / $module;
        self::assertLessThanOrEqual(200, $columns);
        self::assertEqualsWithDelta(2.0, ($right - $left) / ($bottom - $top), 0.4);
    }

    public function testPngHasTheSvgGeometryOnOpaqueWhite(): void
    {
        $f = new Hub3Pdf417();
        $payload = $f->payload($this->details());
        $png = imagecreatefromstring($f->png($payload, 300));
        self::assertNotFalse($png);

        self::assertSame(1, preg_match('/<svg[^>]* width="([\d.]+)" height="([\d.]+)"/', $f->svg($payload, 300), $size));
        self::assertSame([(int)$size[1], (int)$size[2]], [imagesx($png), imagesy($png)]);

        // The quiet zone is white pixels, not transparency that a dark mail client paints over.
        self::assertSame(-1, imagecolortransparent($png));
        $corner = imagecolorsforindex($png, imagecolorat($png, 0, 0));
        self::assertSame(['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 0], $corner);
    }
}
