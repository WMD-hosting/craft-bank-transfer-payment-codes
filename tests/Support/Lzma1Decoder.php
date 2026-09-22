<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\Support;

use RuntimeException;

/**
 * Ported from usmansher/paybysquare-php (MIT, 2026).
 *
 * In-language reference decoder for the payloads produced by
 * src/encoding/Lzma1Encoder.php. Test-only: it lets the suite round-trip an
 * encoded payload back to its TSV data string without an `xz` runtime, so
 * the plugin's tests run standalone on machines without `xz` installed.
 *
 * It is an independent LZMA1 range decoder, but only handles the literal-only
 * stream our encoder emits (literals plus the single end-of-stream marker).
 * It is NOT a general LZMA decoder.
 */
final class Lzma1Decoder
{
    private const K_TOP = 1 << 24;
    private const BIT_MODEL_TOTAL = 2048;
    private const MOVE_BITS = 5;
    private const INIT_PROB = 1024;
    private const LC = 3;
    private const POS_STATE_MASK = 0b11;
    private const NUM_STATES = 12;
    private const NUM_POS_SLOTS = 64;
    private const ALIGN_BITS = 4;
    private const END_POS_MODEL_INDEX = 14;
    private const EOS_DISTANCE = 0xFFFFFFFF;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUV';

    /** @var int[] */
    private array $bytes;
    private int $pos = 0;
    private int $code = 0;
    private int $range = 0xFFFFFFFF;

    private function __construct(string $compressed)
    {
        $this->bytes = array_values(unpack('C*', $compressed) ?: []);
        // Skip the encoder's initial cache byte (always 0), then load the
        // 32-bit code from the next four bytes (big-endian).
        $this->pos = 1;
        for ($i = 0; $i < 4; $i++) {
            $this->code = (($this->code << 8) | $this->nextByte()) & 0xFFFFFFFF;
        }
    }

    private function nextByte(): int
    {
        return $this->bytes[$this->pos++] ?? 0;
    }

    private function normalize(): void
    {
        while ($this->range < self::K_TOP) {
            $this->range = ($this->range << 8) & 0xFFFFFFFF;
            $this->code = (($this->code << 8) | $this->nextByte()) & 0xFFFFFFFF;
        }
    }

    /** @param int[] $probs */
    private function decodeBit(array &$probs, int $index): int
    {
        $prob = $probs[$index];
        $bound = ($this->range >> 11) * $prob;
        if ($this->code < $bound) {
            $this->range = $bound;
            $probs[$index] = $prob + ((self::BIT_MODEL_TOTAL - $prob) >> self::MOVE_BITS);
            $bit = 0;
        } else {
            $this->code -= $bound;
            $this->range -= $bound;
            $probs[$index] = $prob - ($prob >> self::MOVE_BITS);
            $bit = 1;
        }
        $this->normalize();
        return $bit;
    }

    private function decodeDirectBits(int $numBits): int
    {
        $result = 0;
        for ($i = 0; $i < $numBits; $i++) {
            $this->range >>= 1;
            $result <<= 1;
            if ($this->code >= $this->range) {
                $this->code -= $this->range;
                $result |= 1;
            }
            $this->normalize();
        }
        return $result;
    }

    /** @param int[] $probs */
    private function bitTreeDecode(array &$probs, int $offset, int $numBits): int
    {
        $m = 1;
        for ($i = 0; $i < $numBits; $i++) {
            $m = ($m << 1) | $this->decodeBit($probs, $offset + $m);
        }
        return $m - (1 << $numBits);
    }

    /** @param int[] $probs */
    private function reverseBitTreeDecode(array &$probs, int $offset, int $numBits): int
    {
        $m = 1;
        $symbol = 0;
        for ($i = 0; $i < $numBits; $i++) {
            $bit = $this->decodeBit($probs, $offset + $m);
            $m = ($m << 1) | $bit;
            $symbol |= $bit << $i;
        }
        return $symbol;
    }

    /** @return int[] */
    private static function newProbs(int $size): array
    {
        return array_fill(0, $size, self::INIT_PROB);
    }

    private function decompress(int $expectedLength): string
    {
        $isMatch = self::newProbs(self::NUM_STATES << 4);
        $isRep = self::newProbs(self::NUM_STATES);
        $posSlot = self::newProbs(4 * self::NUM_POS_SLOTS);
        $align = self::newProbs(1 << self::ALIGN_BITS);
        $lenChoice = self::newProbs(2);
        $lenLow = self::newProbs(16 * 8);
        $literals = self::newProbs((1 << self::LC) * 0x300);

        $state = 0;
        $out = [];
        $prevByte = 0;

        while (true) {
            $posState = count($out) & self::POS_STATE_MASK;
            if ($this->decodeBit($isMatch, ($state << 4) + $posState) === 0) {
                $litBase = ($prevByte >> (8 - self::LC)) * 0x300;
                $ctx = 1;
                for ($i = 0; $i < 8; $i++) {
                    $ctx = ($ctx << 1) | $this->decodeBit($literals, $litBase + $ctx);
                }
                $byte = $ctx & 0xFF;
                $out[] = $byte;
                $prevByte = $byte;
                continue;
            }

            // Match: our encoder only emits the end-of-stream marker here.
            $this->decodeBit($isRep, $state);
            $this->decodeBit($lenChoice, 0);
            $this->bitTreeDecode($lenLow, $posState * 8, 3);

            $slot = $this->bitTreeDecode($posSlot, 0, 6);
            if ($slot < 4) {
                $dist = $slot;
            } else {
                $footerBits = ($slot >> 1) - 1;
                $dist = (2 | ($slot & 1)) << $footerBits;
                if ($slot >= self::END_POS_MODEL_INDEX) {
                    $dist += $this->decodeDirectBits($footerBits - self::ALIGN_BITS) << self::ALIGN_BITS;
                    $dist += $this->reverseBitTreeDecode($align, 0, self::ALIGN_BITS);
                }
            }
            if (($dist & 0xFFFFFFFF) === self::EOS_DISTANCE) {
                break;
            }
            throw new RuntimeException("unexpected match distance {$dist} in literal-only stream");
        }

        if (count($out) !== $expectedLength) {
            throw new RuntimeException(
                'decompressed length ' . count($out) . " != header length {$expectedLength}",
            );
        }
        return pack('C*', ...$out);
    }

    private static function base32hexDecode(string $payload): string
    {
        $bits = '';
        $length = strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            $value = strpos(self::ALPHABET, $payload[$i]);
            if ($value === false) {
                throw new RuntimeException("invalid base32hex character \"{$payload[$i]}\"");
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $byteCount = intdiv(strlen($bits), 8);
        $out = '';
        for ($i = 0; $i < $byteCount; $i++) {
            $out .= chr((int) bindec(substr($bits, $i * 8, 8)));
        }
        return $out;
    }

    /** Decompress a raw literal-only LZMA1 stream to the original bytes. */
    public static function decompressRaw(string $compressed, int $expectedLength): string
    {
        return (new self($compressed))->decompress($expectedLength);
    }

    /**
     * Decode a full Pay By Square payload back to its TSV data string:
     * base32hex-decode, unwrap the frame, LZMA-decompress, check the length
     * and CRC32, and return the recovered UTF-8 data.
     */
    public static function decode(string $payload): string
    {
        $raw = self::base32hexDecode($payload);
        if (strlen($raw) < 4 || $raw[0] !== "\x00" || $raw[1] !== "\x00") {
            throw new RuntimeException('bad header: expected two leading zero bytes');
        }
        $totalLength = ord($raw[2]) | (ord($raw[3]) << 8);

        $decoder = new self(substr($raw, 4));
        $total = $decoder->decompress($totalLength);

        $checksum = substr($total, 0, 4);
        $data = substr($total, 4);
        $expected = pack('V', crc32($data));
        if ($checksum !== $expected) {
            throw new RuntimeException('CRC32 mismatch');
        }
        return $data;
    }
}
