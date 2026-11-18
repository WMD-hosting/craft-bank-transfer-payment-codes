<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\formats;

use Com\Tecnick\Barcode\Barcode;
use Com\Tecnick\Barcode\Model as BarcodeModel;
use wmd\banktransferpaymentcodes\models\PaymentDetails;

/**
 * Croatian HUB-3 "2D barkod" (HUB 2DBK v6, euro edition, September 2022):
 * fourteen LF-separated fields starting with HRVHUB30, encoded in a PDF417
 * symbol at error-correction level 4. Field lengths are counted in
 * characters; the character set is the Croatian alphabet plus a short list
 * of punctuation, so anything else is transliterated or replaced by a space.
 */
final class Hub3Pdf417 implements FormatInterface
{
    private const ALLOWED = '/[^A-Za-z0-9ČčĆćĐđŠšŽž ,.:\-+?\'\/()]/u';

    public static function handle(): string
    {
        return 'hub3';
    }
    public static function label(): string
    {
        return 'HUB-3 barcode (Croatia)';
    }
    public function countries(): array
    {
        return ['HR'];
    }

    public function supports(PaymentDetails $d): ?string
    {
        if ($d->account->country() !== 'HR') {
            return 'HUB-3 needs a Croatian IBAN.';
        }
        if (strtoupper($d->currency) !== 'EUR') {
            return 'HUB-3 is defined for euro amounts.';
        }
        if ($d->amount <= 0 || $d->amount >= 10_000_000_000_000) {
            return 'Amount out of range.';
        }
        if (!preg_match('/^HR\d{2}$/', $d->referenceModel)) {
            return 'HUB-3 needs an HR reference model.';
        }
        return null;
    }

    public function payload(PaymentDetails $d): string
    {
        if (($reason = $this->supports($d)) !== null) {
            throw new FormatException($reason);
        }
        $a = $d->account;
        return implode("\n", [
            'HRVHUB30',
            'EUR',
            str_pad($d->cents(), 15, '0', STR_PAD_LEFT),
            self::field($d->payerName ?? '', 30),
            self::field($d->payerStreet ?? '', 27),
            self::field(trim(($d->payerPostcode ?? '') . ' ' . ($d->payerCity ?? '')), 27),
            self::field($a->holder, 25),
            self::field($a->street, 25),
            self::field(trim($a->postcode . ' ' . $a->city), 27),
            $a->iban,
            $d->referenceModel,
            self::field($d->reference, 22),
            'COST',
            self::field($d->purpose, 35),
        ]);
    }

    /**
     * Every bar goes into one path, merged across equal consecutive rows. tc-lib's own SVG draws a <rect> per
     * run, and renderers anti-alias the seams between touching rects into grey lines that phones misread when
     * the code is shown on a screen.
     */
    public function svg(string $payload, int $size): string
    {
        $barcode = $this->barcode($payload, $size);
        $a = $barcode->getArray();
        $rows = array_map(static fn(array $row): string => implode('', $row), $barcode->getGridArray('0', '1'));
        $num = static fn(float $v): string => rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
        $d = '';
        for ($y = 0, $n = count($rows); $y < $n; $y = $end) {
            $end = $y + 1;
            while ($end < $n && $rows[$end] === $rows[$y]) {
                $end++;
            }
            preg_match_all('/1+/', $rows[$y], $runs, PREG_OFFSET_CAPTURE);
            foreach ($runs[0] as [$run, $x]) {
                $w = $num(strlen($run) * $a['width_ratio']);
                $d .= 'M' . $num($a['padding']['L'] + $x * $a['width_ratio']) . ' ' . $num($a['padding']['T'] + $y * $a['height_ratio'])
                    . 'h' . $w . 'v' . $num(($end - $y) * $a['height_ratio']) . 'h-' . $w . 'z';
            }
        }
        [$width, $height] = [$num($a['full_width']), $num($a['full_height'])];

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . '<desc>' . htmlspecialchars($payload, ENT_XML1 | ENT_DISALLOWED | ENT_SUBSTITUTE, 'UTF-8') . '</desc>'
            . '<rect width="' . $width . '" height="' . $height . '" fill="#ffffff"/><path fill="#000000" d="' . $d . '"/></svg>';
    }

    public function png(string $payload, int $size): string
    {
        return $this->barcode($payload, $size)->getPngData(false);
    }

    /**
     * Coarse enough for a phone to read off a page or PDF on screen (T 996399).
     *
     * 'PDF417,<aspect ratio>,<error correction level>': HUB fixes level 4. tc-lib picks the column count for
     * rows 2 modules high; drawn with rows twice the module width (height -2m) they are 4 modules high, so
     * aspect 4 is a 2:1 symbol of about 190 modules across. The previous aspect 9 with 6-module rows gave
     * about 260 modules, too dense for a phone camera at the sizes the code is shown.
     *
     * Negative width/height are pixels per module column/row, so every bar lands on whole pixels in the PNG.
     * One module per 100 px of $size keeps the PNG at about the width it had (590 px at the default 300).
     */
    private function barcode(string $payload, int $size): BarcodeModel
    {
        $module = max(1, (int)round($size / 100));
        return (new Barcode())
            ->getBarcodeObj('PDF417,4,4', $payload, -$module, -2 * $module, 'black', [8, 8, 8, 8])
            ->setBackgroundColor('white');
    }

    /** Transliterate what the HUB character set forbids, replace the rest with spaces, cut to length. */
    public static function field(string $text, int $max): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/', ' ', $text) ?? '');
        $text = preg_replace_callback('/[^\x00-\x7FČčĆćĐđŠšŽž]/u', static function(array $m): string {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $m[0]);
            return $ascii === false || $ascii === '' || $ascii === '?' ? ' ' : $ascii;
        }, $text) ?? '';
        $text = preg_replace(self::ALLOWED, ' ', $text) ?? '';
        return rtrim(mb_substr($text, 0, $max));
    }
}
