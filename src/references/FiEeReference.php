<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

/**
 * Finnish viitenumero and Estonian viitenumber: up to 19 digits plus a
 * check digit computed with weights 7, 3, 1 from the right.
 */
final class FiEeReference implements ReferenceSchemeInterface
{
    public function handle(): string
    {
        return 'fi';
    }
    public function label(): string
    {
        return 'Finnish / Estonian reference number';
    }
    public function countries(): array
    {
        return ['FI', 'EE'];
    }
    public function model(): ?string
    {
        return null;
    }

    public function generate(ReferenceInput $input): string
    {
        // The standard sets a minimum of 4 characters including the check digit,
        // so a one- or two-digit order number has to be padded to reach it.
        $body = str_pad(substr($input->digits(), 0, 19), 3, '0', STR_PAD_LEFT);
        return $body . self::check($body);
    }

    public function validate(string $reference): bool
    {
        $digits = $this->normalise($reference);
        if (!preg_match('/^\d{4,20}$/', $digits)) {
            return false;
        }
        return self::check(substr($digits, 0, -1)) === (int)substr($digits, -1);
    }

    public function normalise(string $reference): string
    {
        return preg_replace('/\D+/', '', $reference) ?? '';
    }

    public static function check(string $digits): int
    {
        $weights = [7, 3, 1];
        $sum = 0;
        foreach (array_values(array_reverse(str_split($digits))) as $i => $digit) {
            $sum += (int)$digit * $weights[$i % 3];
        }
        return (10 - ($sum % 10)) % 10;
    }
}
