<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\helpers;

use wmd\banktransferpaymentcodes\references\Rf;

/**
 * IBAN normalisation and mod-97 checksum validation (ISO 13616), plus an
 * EEA lookup used to decide whether a BIC is required alongside the IBAN.
 */
final class Iban
{
    private const EEA = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IS', 'IE', 'IT', 'LV', 'LI', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    public static function normalise(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    public static function isValid(string $iban): bool
    {
        $clean = self::normalise($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $clean)) {
            return false;
        }
        return Rf::mod97(substr($clean, 4) . substr($clean, 0, 4)) === 1;
    }

    public static function country(string $iban): string
    {
        return substr(self::normalise($iban), 0, 2);
    }

    public static function isEea(string $country): bool
    {
        return in_array(strtoupper($country), self::EEA, true);
    }
}
