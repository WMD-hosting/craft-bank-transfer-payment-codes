<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\formats;

use wmd\banktransferpaymentcodes\models\PaymentDetails;
use wmd\banktransferpaymentcodes\rendering\QrRenderer;

/**
 * Slovenian UPN QR (ZBS technical standard v1.1): nineteen LF-terminated
 * fields followed by a three-digit checksum equal to the byte length of
 * fields 1..19 including their LFs. The symbol is fixed at version 15, byte
 * mode, error correction M, ISO-8859-2 with ECI 4.
 */
final class UpnQr implements FormatInterface
{
    public static function handle(): string
    {
        return 'upn';
    }
    public static function label(): string
    {
        return 'UPN QR (Slovenia)';
    }
    public function countries(): array
    {
        return ['SI'];
    }

    public function supports(PaymentDetails $d): ?string
    {
        if ($d->account->country() !== 'SI') {
            return 'UPN QR needs a Slovenian IBAN.';
        }
        if (strtoupper($d->currency) !== 'EUR') {
            return 'UPN QR is defined for euro amounts.';
        }
        if ($d->amount <= 0 || $d->amount >= 1_000_000_000) {
            return 'Amount out of range.';
        }
        $referenceOk = preg_match('/^SI\d{2}$/', $d->referenceModel) === 1 || str_starts_with($d->reference, 'RF');
        if (!$referenceOk) {
            return 'UPN QR needs an SI model or an RF reference.';
        }
        return null;
    }

    public function payload(PaymentDetails $d): string
    {
        if (($reason = $this->supports($d)) !== null) {
            throw new FormatException($reason);
        }
        $a = $d->account;
        $reference = str_starts_with($d->reference, 'RF') ? $d->reference : $d->referenceModel . $d->reference;
        $fields = [
            'UPNQR',
            '',                                             // payer IBAN
            '',                                             // deposit
            '',                                             // withdrawal
            '',                                             // payer reference
            self::field($d->payerName ?? '', 33),
            self::field($d->payerStreet ?? '', 33),
            self::field(trim(($d->payerPostcode ?? '') . ' ' . ($d->payerCity ?? '')), 33),
            str_pad($d->cents(), 11, '0', STR_PAD_LEFT),
            '',                                             // payment date
            '',                                             // urgent
            'OTHR',
            self::field($d->purpose, 42),
            '',                                             // due date
            $a->iban,
            self::field($reference, 26),
            self::field($a->holder, 33),
            self::field($a->street, 33),
            self::field(trim($a->postcode . ' ' . $a->city), 33),
        ];
        $body = implode("\n", $fields) . "\n";
        $length = strlen(self::latin2($body));
        return $body . str_pad((string)$length, 3, '0', STR_PAD_LEFT);
    }

    public function svg(string $payload, int $size): string
    {
        return QrRenderer::svg($payload, $size, 'ISO-8859-2', true, 15);
    }

    public function png(string $payload, int $size): string
    {
        return QrRenderer::png($payload, $size, 'ISO-8859-2', true, 15);
    }

    private static function field(string $text, int $max): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/', ' ', $text) ?? '');
        // Keep only what ISO-8859-2 can carry; anything else becomes a space.
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_map(
            static fn(string $c) => @iconv('UTF-8', 'ISO-8859-2', $c) === false ? ' ' : $c,
            $chars
        );
        return mb_substr(implode('', $kept), 0, $max);
    }

    private static function latin2(string $utf8): string
    {
        return iconv('UTF-8', 'ISO-8859-2//TRANSLIT', $utf8) ?: '';
    }
}
