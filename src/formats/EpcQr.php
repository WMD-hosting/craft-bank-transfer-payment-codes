<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\formats;

use wmd\banktransferpaymentcodes\helpers\Iban;
use wmd\banktransferpaymentcodes\models\PaymentDetails;
use wmd\banktransferpaymentcodes\rendering\QrRenderer;

/**
 * EPC069-12 v3.1 "SEPA credit transfer QR" (GiroCode, Zahlen mit Code).
 * Version 002 without BIC unless the IBAN is outside the EEA or the merchant
 * forces it; UTF-8 (charset 1); LF separators; no trailing newline; at most
 * 331 bytes; error correction M; no ECI segment.
 */
final class EpcQr implements FormatInterface
{
    private const MAX_BYTES = 331;
    /** EPC069-12 field length of the unstructured remittance line. */
    private const MAX_CHARS = 140;

    public function __construct(private readonly bool $forceBic = false)
    {
    }

    public static function handle(): string
    {
        return 'epc';
    }
    public static function label(): string
    {
        return 'EPC QR (SEPA)';
    }
    public function countries(): array
    {
        return [];
    }

    public function supports(PaymentDetails $d): ?string
    {
        if (strtoupper($d->currency) !== 'EUR') {
            return 'EPC QR is defined for euro amounts only.';
        }
        if ($d->amount < 0.01 || $d->amount > 999999999.99) {
            return 'Amount must be between 0.01 and 999999999.99.';
        }
        if (!Iban::isValid($d->account->iban)) {
            return 'IBAN is invalid.';
        }
        if ($this->needsBic($d->account->country()) && $d->account->bic === '') {
            return 'BIC is required for this account.';
        }
        if ($d->referenceIsStructured && mb_strlen(self::clean($d->reference)) > 35) {
            // Truncating a structured creditor reference breaks its check digits,
            // so the bank would reject the payment or apply it to nothing. The
            // free-text field below is shortened happily; this one is not.
            return 'Structured reference exceeds the 35-character EPC limit.';
        }
        return null;
    }

    public function payload(PaymentDetails $d): string
    {
        if (($reason = $this->supports($d)) !== null) {
            throw new FormatException($reason);
        }
        $withBic = $this->needsBic($d->account->country());
        // Purpose first: a payer scanning this sees "Order 1234 12345678" in
        // their banking app rather than a bare run of digits. The reference is
        // the half that identifies the payment, so only the purpose is ever
        // shortened; $purpose is the shrinkable budget, $tail is untouchable.
        $tail = $d->referenceIsStructured ? '' : self::clean($d->reference);
        $purpose = $d->referenceIsStructured ? '' : self::clean($d->purpose);
        $purpose = self::fitPurpose($purpose, $tail, self::MAX_CHARS);
        $lines = [
            'BCD',
            $withBic ? '001' : '002',
            '1',
            'SCT',
            $withBic ? $d->account->bic : '',
            mb_substr(self::clean($d->account->holder), 0, 70),
            $d->account->iban,
            'EUR' . self::amount($d->amount),
            '',
            $d->referenceIsStructured ? self::clean($d->reference) : '',
            self::remittance($purpose, $tail),
        ];
        $payload = self::join($lines);
        // Shrink until the byte budget fits: the payment purpose first.
        while (strlen($payload) > self::MAX_BYTES && $purpose !== '') {
            $purpose = mb_substr($purpose, 0, mb_strlen($purpose) - 1);
            $lines[10] = self::remittance($purpose, $tail);
            $payload = self::join($lines);
        }
        // Then the beneficiary name; Verification of Payee wants it whole, but a
        // shortened name still reaches the right account, a broken reference does not.
        while (strlen($payload) > self::MAX_BYTES && mb_strlen($lines[5]) > 1) {
            $lines[5] = mb_substr($lines[5], 0, mb_strlen($lines[5]) - 1);
            $payload = self::join($lines);
        }
        // Only now, as a last resort, the reference itself.
        while (strlen($payload) > self::MAX_BYTES && $lines[10] !== '') {
            $lines[10] = mb_substr($lines[10], 0, mb_strlen($lines[10]) - 1);
            $payload = self::join($lines);
        }
        if (strlen($payload) > self::MAX_BYTES) {
            throw new FormatException('EPC payload exceeds 331 bytes.');
        }
        return $payload;
    }

    public function svg(string $payload, int $size): string
    {
        return QrRenderer::svg($payload, $size, 'UTF-8', false, null);
    }

    public function png(string $payload, int $size): string
    {
        return QrRenderer::png($payload, $size, 'UTF-8', false, null);
    }

    /** The unstructured remittance line: purpose, then the reference. */
    private static function remittance(string $purpose, string $tail): string
    {
        return trim($purpose . ' ' . $tail);
    }

    /** As much of the purpose as fits beside the reference within $max characters. */
    private static function fitPurpose(string $purpose, string $tail, int $max): string
    {
        while ($purpose !== '' && mb_strlen(self::remittance($purpose, $tail)) > $max) {
            $purpose = mb_substr($purpose, 0, mb_strlen($purpose) - 1);
        }
        return trim($purpose);
    }

    /** Drop the trailing empty fields and join with LF; the last field carries no separator. */
    private static function join(array $lines): string
    {
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        return implode("\n", $lines);
    }

    public static function amount(float $amount): string
    {
        $s = number_format($amount, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $text) ?? '');
    }

    private function needsBic(string $country): bool
    {
        return $this->forceBic || !Iban::isEea($country);
    }
}
