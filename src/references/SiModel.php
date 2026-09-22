<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

/**
 * Slovenian model SI12: up to 12 digits plus a MOD 11 check digit with
 * weights 2..13 from the right; a result of 10 or 11 becomes 0.
 */
final class SiModel implements ReferenceSchemeInterface
{
    public function handle(): string
    {
        return 'si12';
    }
    public function label(): string
    {
        return 'Slovenian model SI12';
    }
    public function countries(): array
    {
        return ['SI'];
    }
    public function model(): string
    {
        return 'SI12';
    }

    public function generate(ReferenceInput $input): string
    {
        $digits = substr($input->digits(), 0, 12);
        return $digits . self::check($digits);
    }

    public function validate(string $reference): bool
    {
        $digits = $this->normalise($reference);
        if (!preg_match('/^\d{2,13}$/', $digits)) {
            return false;
        }
        return self::check(substr($digits, 0, -1)) === (int)substr($digits, -1);
    }

    public function normalise(string $reference): string
    {
        $clean = preg_replace('/^SI\d{2}/i', '', preg_replace('/\s+/', '', $reference) ?? '') ?? '';
        return preg_replace('/\D+/', '', $clean) ?? '';
    }

    public static function check(string $digits): int
    {
        $weight = 2;
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $digit) {
            $sum += (int)$digit * $weight++;
        }
        $check = 11 - ($sum % 11);
        return $check >= 10 ? 0 : $check;
    }
}
