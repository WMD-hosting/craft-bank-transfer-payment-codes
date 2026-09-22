<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\encoding;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\encoding\Base32Hex;
use wmd\banktransferpaymentcodes\encoding\Lzma1Encoder;
use wmd\banktransferpaymentcodes\tests\Support\Lzma1Decoder;

final class Lzma1Test extends TestCase
{
    public function testRoundTrip(): void
    {
        // Lzma1Decoder::decode() mirrors PayBySquare::payload(): it expects the
        // frame's compressed payload to decompress to a 4-byte CRC32 followed by
        // the original data (checked, then stripped), not the bare data. Frame
        // it the same way payload() does so the full pipeline round-trips.
        foreach (['', 'a', "\t1\t1\t10.00\tEUR\t20260721", str_repeat("Žluťoučký kůň\n", 50)] as $input) {
            $total = pack('V', crc32($input)) . $input;
            $framed = chr(0) . chr(0) . pack('v', strlen($total)) . Lzma1Encoder::compress($total);
            self::assertSame($input, Lzma1Decoder::decode(Base32Hex::encode($framed)));
        }
    }

    public function testBase32HexAlphabet(): void
    {
        self::assertSame('CO', Base32Hex::encode('f'));
        self::assertSame('CPNG', Base32Hex::encode('fo'));
        self::assertSame('CPNMU', Base32Hex::encode('foo'));
    }
}
