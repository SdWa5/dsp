<?php

namespace App\Measurement;

/**
 * Reads one channel of a PCM (16, 24, 32 bit) or IEEE float (32 bit) WAV file as floats in -1..1.
 */
final class WavReader
{
    private const PCM = 1;
    private const FLOAT = 3;
    private const EXTENSIBLE = 0xFFFE;

    /**
     * @param int $channel 1-based
     *
     * @return array{rate: int, channels: int, samples: list<float>}
     */
    public static function read(string $path, int $channel = 1): array
    {
        $data = @file_get_contents($path);
        if (false === $data) {
            throw new \InvalidArgumentException(\sprintf('Cannot read "%s".', $path));
        }

        return self::parse($data, $channel);
    }

    /**
     * @return array{rate: int, channels: int, samples: list<float>}
     */
    public static function parse(string $data, int $channel = 1): array
    {
        if (\strlen($data) < 12 || 'RIFF' !== substr($data, 0, 4) || 'WAVE' !== substr($data, 8, 4)) {
            throw new \InvalidArgumentException('Not a WAV file.');
        }
        $format = null;
        $offset = 12;
        while ($offset + 8 <= \strlen($data)) {
            $id = substr($data, $offset, 4);
            $size = self::u32($data, $offset + 4);
            $body = $offset + 8;
            if ('fmt ' === $id) {
                $format = self::format($data, $body, $size);
            } elseif ('data' === $id) {
                if (null === $format) {
                    throw new \InvalidArgumentException('The WAV file has no format before its data.');
                }
                // arecord writes 0 or 0xFFFFFFFF as the data size when it is stopped early
                $size = 0 === $size || $size > \strlen($data) - $body ? \strlen($data) - $body : $size;

                return ['rate' => $format['rate'], 'channels' => $format['channels'], 'samples' => self::samples(substr($data, $body, $size), $format, $channel)];
            }
            $offset = $body + $size + ($size & 1);
        }

        throw new \InvalidArgumentException('The WAV file has no data chunk.');
    }

    /**
     * @return array{type: int, channels: int, rate: int, bits: int}
     */
    private static function format(string $data, int $offset, int $size): array
    {
        if ($size < 16) {
            throw new \InvalidArgumentException('The WAV format chunk is too short.');
        }
        $f = unpack('vtype/vchannels/Vrate/Vbyterate/valign/vbits', $data, $offset);
        \assert(\is_array($f));
        $type = (int) $f['type'];
        if (self::EXTENSIBLE === $type && $size >= 26) {
            $sub = unpack('v', $data, $offset + 24);
            \assert(\is_array($sub));
            $type = (int) $sub[1];
        }
        $format = ['type' => $type, 'channels' => (int) $f['channels'], 'rate' => (int) $f['rate'], 'bits' => (int) $f['bits']];
        $supported = (self::PCM === $type && \in_array($format['bits'], [16, 24, 32], true)) || (self::FLOAT === $type && 32 === $format['bits']);
        if (!$supported || $format['channels'] < 1) {
            throw new \InvalidArgumentException(\sprintf('Unsupported WAV format %d with %d bits. Use 16/24/32 bit PCM or 32 bit float.', $type, $format['bits']));
        }

        return $format;
    }

    /**
     * @param array{type: int, channels: int, rate: int, bits: int} $format
     *
     * @return list<float>
     */
    private static function samples(string $pcm, array $format, int $channel): array
    {
        if ($channel < 1 || $channel > $format['channels']) {
            throw new \InvalidArgumentException(\sprintf('Channel %d does not exist, the file has %d.', $channel, $format['channels']));
        }
        $width = intdiv($format['bits'], 8);
        $frame = $width * $format['channels'];
        $count = intdiv(\strlen($pcm), $frame);
        $at = ($channel - 1) * $width;
        $samples = [];
        for ($i = 0; $i < $count; ++$i, $at += $frame) {
            $samples[] = match (true) {
                self::FLOAT === $format['type'] => self::float32($pcm, $at),
                2 === $width => self::signed(\ord($pcm[$at]) | \ord($pcm[$at + 1]) << 8, 16) / 32768,
                3 === $width => self::signed(\ord($pcm[$at]) | \ord($pcm[$at + 1]) << 8 | \ord($pcm[$at + 2]) << 16, 24) / 8388608,
                default => self::signed(self::u32($pcm, $at), 32) / 2147483648,
            };
        }

        return $samples;
    }

    private static function signed(int $value, int $bits): int
    {
        return $value >= 1 << ($bits - 1) ? $value - (1 << $bits) : $value;
    }

    private static function float32(string $data, int $offset): float
    {
        $v = unpack('g', $data, $offset);
        \assert(\is_array($v));

        return (float) $v[1];
    }

    private static function u32(string $data, int $offset): int
    {
        $v = unpack('V', $data, $offset);
        \assert(\is_array($v));

        return (int) $v[1];
    }
}
