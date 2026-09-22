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

    public function svg(string $payload, int $size): string
    {
        return $this->barcode($payload, $size)->getSvgCode();
    }

    public function png(string $payload, int $size): string
    {
        return $this->barcode($payload, $size)->getPngData(false);
    }

    private function barcode(string $payload, int $size): BarcodeModel
    {
        // 'PDF417,<aspect ratio>,<error correction level>': HUB fixes level 4.
        // Aspect ratio 9 approximates the HUB symbol's real 58x26mm print
        // geometry (~2.2:1); ratio 3 (the tc-lib default) packs the columns
        // into a near-square symbol instead. Negative width/height are
        // module-size multipliers (pixels per module column/row); the
        // caller's target width is honoured by scaling the module width,
        // with the row height kept at 3x the module (independent of the
        // PDF417 aspect-ratio param, which only controls column/row count).
        $module = max(1, (int)round($size / 200));
        return (new Barcode())->getBarcodeObj('PDF417,9,4', $payload, -$module, -$module * 3, 'black', [8, 8, 8, 8]);
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
