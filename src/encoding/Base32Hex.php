<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\encoding;

/**
 * Ported from usmansher/paybysquare-php (MIT, 2026).
 */
final class Base32Hex
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUV';

    /**
     * Pack bytes into the Pay By Square character encoding: the bit stream
     * is zero-padded on the right to a multiple of 5 and each 5-bit group
     * maps to one character of the base32hex alphabet (no `=` padding).
     */
    public static function encode(string $data): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }
        return $out;
    }
}
