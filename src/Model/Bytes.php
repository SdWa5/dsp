<?php

namespace App\Model;

/**
 * Little-endian readers for the configuration blob.
 */
final class Bytes
{
    public static function u8(string $bytes, int $offset): int
    {
        return \ord($bytes[$offset]);
    }

    public static function u16(string $bytes, int $offset): int
    {
        return \ord($bytes[$offset]) | \ord($bytes[$offset + 1]) << 8;
    }

    /**
     * NUL-terminated ASCII, right-trimmed.
     */
    public static function name(string $bytes, int $offset, int $length = 8): string
    {
        $raw = explode("\x00", substr($bytes, $offset, $length))[0];

        return rtrim(preg_replace('/[^\x20-\x7E]/', '?', $raw) ?? '');
    }
}
