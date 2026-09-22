<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

/**
 * Croatian "poziv na broj" models HR00 (no check digit, the default) and
 * HR01 (ISO 7064 MOD 11,10 over the whole reference). Up to three parts of
 * 12 digits separated by dashes; 22 characters in total.
 */
final class HrModel implements ReferenceSchemeInterface
{
    public function __construct(private readonly bool $withCheckDigit = false)
    {
    }

    public function handle(): string
    {
        return $this->withCheckDigit ? 'hr01' : 'hr00';
    }
    public function label(): string
    {
        return $this->withCheckDigit ? 'Croatian model HR01' : 'Croatian model HR00';
    }
    public function countries(): array
    {
        return ['HR'];
    }
    public function model(): string
    {
        return $this->withCheckDigit ? 'HR01' : 'HR00';
    }

    public function generate(ReferenceInput $input): string
    {
        $digits = substr($input->digits(), 0, $this->withCheckDigit ? 11 : 12);
        return $this->withCheckDigit ? $digits . self::iso7064($digits) : $digits;
    }

    public function validate(string $reference): bool
    {
        $clean = preg_replace('/^HR\d{2}\s*/i', '', trim($reference)) ?? '';
        $clean = str_replace(' ', '', $clean);
        if (!preg_match('/^\d{1,12}(-\d{1,12}){0,2}$/', $clean) || strlen($clean) > 22) {
            return false;
        }
        if (!$this->withCheckDigit) {
            return true;
        }
        $digits = str_replace('-', '', $clean);
        return self::iso7064(substr($digits, 0, -1)) === (int)substr($digits, -1);
    }

    public function normalise(string $reference): string
    {
        $clean = preg_replace('/^HR\d{2}/i', '', preg_replace('/\s+/', '', $reference) ?? '') ?? '';
        return preg_replace('/\D+/', '', $clean) ?? '';
    }

    /** ISO 7064 MOD 11,10 check digit. */
    public static function iso7064(string $digits): int
    {
        $p = 10;
        foreach (str_split($digits) as $digit) {
            $t = ($p + (int)$digit) % 10;
            if ($t === 0) {
                $t = 10;
            }
            $p = ($t * 2) % 11;
        }
        return (11 - $p) % 10;
    }
}
