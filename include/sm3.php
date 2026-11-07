<?php
declare(strict_types=1);

/**
 * Pure-PHP SM3 implementation (GB/T 32905-2016).
 *
 * PHP builds with OpenSSL SM3 support use the native implementation when
 * available; this class is the portable fallback.
 */
final class Sm3
{
    private const IV = [
        0x7380166f, 0x4914b2b9, 0x172442d7, 0xda8a0600,
        0xa96f30bc, 0x163138aa, 0xe38dee4d, 0xb0fb0e4e,
    ];

    /** Returns the lowercase hexadecimal SM3 digest. */
    public static function hex(string $data): string
    {
        if (in_array('sm3', hash_algos(), true)) {
            return hash('sm3', $data);
        }

        return bin2hex(self::digest($data));
    }

    /** Returns the raw 32-byte SM3 digest. */
    public static function digest(string $data): string
    {
        if (in_array('sm3', hash_algos(), true)) {
            return hash('sm3', $data, true);
        }

        $bitLength = strlen($data) * 8;
        $data .= "\x80";
        $data .= str_repeat("\x00", (56 - (strlen($data) % 64) + 64) % 64);
        $data .= pack('J', $bitLength);

        $v = self::IV;
        $length = strlen($data);

        for ($offset = 0; $offset < $length; $offset += 64) {
            $words = array_values(unpack('N16', substr($data, $offset, 64)));
            $words = array_pad($words, 68, 0);

            for ($j = 16; $j < 68; $j++) {
                $mixed = ($words[$j - 16] ^ $words[$j - 9] ^ self::rotl($words[$j - 3], 15)) & 0xFFFFFFFF;
                $words[$j] = (self::p1($mixed) ^ self::rotl($words[$j - 13], 7) ^ $words[$j - 6]) & 0xFFFFFFFF;
            }

            $expanded = [];
            for ($j = 0; $j < 64; $j++) {
                $expanded[$j] = ($words[$j] ^ $words[$j + 4]) & 0xFFFFFFFF;
            }

            $a = $v[0];
            $b = $v[1];
            $c = $v[2];
            $d = $v[3];
            $e = $v[4];
            $f = $v[5];
            $g = $v[6];
            $h = $v[7];

            for ($j = 0; $j < 64; $j++) {
                $t = $j < 16 ? 0x79cc4519 : 0x7a879d8a;
                $a12 = self::rotl($a, 12);
                $ss1 = self::rotl(self::add($a12, $e, self::rotl($t, $j % 32)), 7);
                $ss2 = ($ss1 ^ $a12) & 0xFFFFFFFF;
                $tt1 = self::add(self::ff($j, $a, $b, $c), $d, $ss2, $expanded[$j]);
                $tt2 = self::add(self::gg($j, $e, $f, $g), $h, $ss1, $words[$j]);

                $d = $c;
                $c = self::rotl($b, 9);
                $b = $a;
                $a = $tt1;
                $h = $g;
                $g = self::rotl($f, 19);
                $f = $e;
                $e = self::p0($tt2);
            }

            $v[0] = ($v[0] ^ $a) & 0xFFFFFFFF;
            $v[1] = ($v[1] ^ $b) & 0xFFFFFFFF;
            $v[2] = ($v[2] ^ $c) & 0xFFFFFFFF;
            $v[3] = ($v[3] ^ $d) & 0xFFFFFFFF;
            $v[4] = ($v[4] ^ $e) & 0xFFFFFFFF;
            $v[5] = ($v[5] ^ $f) & 0xFFFFFFFF;
            $v[6] = ($v[6] ^ $g) & 0xFFFFFFFF;
            $v[7] = ($v[7] ^ $h) & 0xFFFFFFFF;
        }

        return pack('N8', ...$v);
    }

    private static function add(int ...$values): int
    {
        $sum = 0;
        foreach ($values as $value) {
            $sum = ($sum + $value) & 0xFFFFFFFF;
        }
        return $sum;
    }

    private static function rotl(int $x, int $n): int
    {
        $x &= 0xFFFFFFFF;
        $n &= 31;
        if ($n === 0) {
            return $x;
        }
        return (($x << $n) | ($x >> (32 - $n))) & 0xFFFFFFFF;
    }

    private static function p0(int $x): int
    {
        $x &= 0xFFFFFFFF;
        return ($x ^ self::rotl($x, 9) ^ self::rotl($x, 17)) & 0xFFFFFFFF;
    }

    private static function p1(int $x): int
    {
        $x &= 0xFFFFFFFF;
        return ($x ^ self::rotl($x, 15) ^ self::rotl($x, 23)) & 0xFFFFFFFF;
    }

    private static function ff(int $j, int $x, int $y, int $z): int
    {
        if ($j < 16) {
            return ($x ^ $y ^ $z) & 0xFFFFFFFF;
        }
        return (($x & $y) | ($x & $z) | ($y & $z)) & 0xFFFFFFFF;
    }

    private static function gg(int $j, int $x, int $y, int $z): int
    {
        if ($j < 16) {
            return ($x ^ $y ^ $z) & 0xFFFFFFFF;
        }
        return (($x & $y) | ((~$x & 0xFFFFFFFF) & $z)) & 0xFFFFFFFF;
    }
}
