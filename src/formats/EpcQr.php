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
        return null;
    }

    public function payload(PaymentDetails $d): string
    {
        if (($reason = $this->supports($d)) !== null) {
            throw new FormatException($reason);
        }
        $withBic = $this->needsBic($d->account->country());
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
            $d->referenceIsStructured ? mb_substr(self::clean($d->reference), 0, 35) : '',
            $d->referenceIsStructured ? '' : mb_substr(self::clean($d->reference !== '' ? $d->reference : $d->purpose), 0, 140),
        ];
        $payload = self::join($lines);
        // Shrink the free text until the byte budget fits.
        while (strlen($payload) > self::MAX_BYTES && $lines[10] !== '') {
            $lines[10] = mb_substr($lines[10], 0, mb_strlen($lines[10]) - 1);
            $payload = self::join($lines);
        }
        // If still over budget, shrink the beneficiary name.
        while (strlen($payload) > self::MAX_BYTES && mb_strlen($lines[5]) > 1) {
            $lines[5] = mb_substr($lines[5], 0, mb_strlen($lines[5]) - 1);
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
