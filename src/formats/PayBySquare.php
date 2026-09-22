<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\formats;

use wmd\banktransferpaymentcodes\encoding\Base32Hex;
use wmd\banktransferpaymentcodes\encoding\Lzma1Encoder;
use wmd\banktransferpaymentcodes\models\PaymentDetails;
use wmd\banktransferpaymentcodes\rendering\QrRenderer;

/**
 * Slovak PAY by square (bysquare spec 1.2.0): tab-separated fields, CRC32
 * prefix, raw LZMA1, a four-byte frame header, base32hex. The beneficiary
 * name is mandatory since 2025-04-01 and every rendered code must carry the
 * "PAY by square" logo frame, which svg() adds.
 */
final class PayBySquare implements FormatInterface
{
    public static function handle(): string
    {
        return 'paybysquare';
    }
    public static function label(): string
    {
        return 'PAY by square (Slovakia)';
    }
    public function countries(): array
    {
        return ['SK'];
    }

    public function supports(PaymentDetails $d): ?string
    {
        if ($d->account->holder === '') {
            return 'PAY by square requires the beneficiary name.';
        }
        if ($d->amount <= 0) {
            return 'Amount must be positive.';
        }
        if (!preg_match('/^[A-Z]{3}$/', strtoupper($d->currency))) {
            return 'Currency must be an ISO 4217 code.';
        }
        return null;
    }

    public static function dataString(PaymentDetails $d): string
    {
        $a = $d->account;
        $variable = preg_replace('/\D+/', '', $d->reference) ?? '';
        $note = str_starts_with($d->reference, 'RF') ? $d->reference : $d->purpose;
        return implode("\t", [
            '',                                     // invoice id
            '1',                                    // number of payments
            '1',                                    // payment option: transfer
            number_format($d->amount, 2, '.', ''),
            strtoupper($d->currency),
            '',                                     // due date
            substr($variable, 0, 10),               // variable symbol
            '',                                     // constant symbol
            '',                                     // specific symbol
            '',                                     // originator reference
            mb_substr(self::clean($note), 0, 140),
            '1',                                    // number of bank accounts
            $a->iban,
            $a->bic,
            '0',                                    // standing order
            '0',                                    // direct debit
            mb_substr(self::clean($a->holder), 0, 70),
            mb_substr(self::clean($a->street), 0, 70),
            mb_substr(self::clean(trim($a->postcode . ' ' . $a->city)), 0, 70),
        ]);
    }

    public function payload(PaymentDetails $d): string
    {
        if (($reason = $this->supports($d)) !== null) {
            throw new FormatException($reason);
        }
        $data = self::dataString($d);
        $total = pack('V', crc32($data)) . $data;
        $compressed = Lzma1Encoder::compress($total);
        return Base32Hex::encode(chr(0x00) . chr(0x00) . pack('v', strlen($total)) . $compressed);
    }

    public function svg(string $payload, int $size): string
    {
        $qr = QrRenderer::svg($payload, $size, 'UTF-8', false, null);
        $frame = file_get_contents(__DIR__ . '/../assets/pay-by-square-frame.svg') ?: '';
        // Wrap: outer SVG 1.25× the code, code centred, wordmark band below.
        $outer = (int)round($size * 1.25);
        $inner = $outer - 8;
        $qrBody = preg_replace('/^<\?xml[^>]*>\s*/', '', $qr) ?? $qr;
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $outer . '" height="' . $outer . '" viewBox="0 0 ' . $outer . ' ' . $outer . '">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . str_replace('<svg ', '<svg x="' . (int)(($outer - $size) / 2) . '" y="' . (int)($size * 0.06) . '" ', $qrBody)
            . str_replace('{{inner}}', (string)$inner, $frame)
            . '</svg>';
    }

    public function png(string $payload, int $size): string
    {
        // PNG has no frame; the spec's logo requirement is met on the web (SVG).
        // Email and PDF use this PNG plus the wordmark printed as text by the partial.
        return QrRenderer::png($payload, $size, 'UTF-8', false, null);
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $text) ?? '');
    }
}
