<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

/**
 * ISO 11649 creditor reference: RF + two check digits + up to 21 alphanumerics.
 */
final class Rf implements ReferenceSchemeInterface
{
    public function handle(): string
    {
        return 'rf';
    }
    public function label(): string
    {
        return 'RF creditor reference (ISO 11649)';
    }
    public function countries(): array
    {
        return [];
    }
    public function model(): ?string
    {
        return null;
    }

    public function generate(ReferenceInput $input): string
    {
        $body = substr($input->digits(), 0, 21);
        return 'RF' . self::checkDigits($body) . $body;
    }

    public function validate(string $reference): bool
    {
        $clean = $this->normaliseKeepPrefix($reference);
        if (!preg_match('/^RF\d{2}[A-Z0-9]{1,21}$/', $clean)) {
            return false;
        }
        return self::mod97(substr($clean, 4) . substr($clean, 0, 4)) === 1;
    }

    public function normalise(string $reference): string
    {
        $clean = $this->normaliseKeepPrefix($reference);
        return preg_match('/^RF\d{2}/', $clean) ? substr($clean, 4) : $clean;
    }

    private function normaliseKeepPrefix(string $reference): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $reference) ?? '');
    }

    private static function checkDigits(string $body): string
    {
        $remainder = self::mod97($body . 'RF00');
        return str_pad((string)(98 - $remainder), 2, '0', STR_PAD_LEFT);
    }

    /** mod 97 over the string with letters expanded to 10–35, computed digit by digit. */
    public static function mod97(string $value): int
    {
        $expanded = '';
        foreach (str_split(strtoupper($value)) as $char) {
            $expanded .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($expanded) as $digit) {
            $remainder = ($remainder * 10 + (int)$digit) % 97;
        }
        return $remainder;
    }
}
