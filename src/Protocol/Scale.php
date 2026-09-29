<?php

namespace App\Protocol;

/**
 * Conversions between raw protocol values and display units, checked against the vendor editor.
 */
final class Scale
{
    public const GAIN_MAX = 400;
    public const GAIN_UNITY = 280;
    public const FREQ_STEPS = 300;
    public const FREQ_BASE = 19.6875;
    public const PEQ_GAIN_MAX = 240;
    public const PEQ_GAIN_UNITY = 120;
    public const Q_STEPS = 100;
    public const SAMPLE_RATE = 48000;
    public const SPEED_OF_SOUND = 343.0;
    public const FEET_PER_METRE = 3.2808;
    public const METER_ZERO = 15906;
    public const METER_PER_DOUBLING = 128;
    public const METER_FLOOR = -90.0;

    /**
     * Channel gain: 0..79 in 0.5 dB steps from -60 dB, from 80 in 0.1 dB steps from -20 dB.
     * 20 = -50, 80 = -20, 280 = 0, 400 = +12 dB.
     */
    public static function gainToDb(int $raw): float
    {
        if ($raw < 0 || $raw > self::GAIN_MAX) {
            throw new \OutOfRangeException(\sprintf('Gain raw value must be 0..%d, got %d.', self::GAIN_MAX, $raw));
        }

        return $raw < 80 ? round($raw / 2 - 60, 1) : round(($raw - 80) / 10 - 20, 1);
    }

    /**
     * Inverse of gainToDb(), rounded to the nearest step.
     */
    public static function dbToGain(float $db): int
    {
        if ($db < -60 || $db > 12) {
            throw new \OutOfRangeException(\sprintf('Gain must be -60..+12 dB, got %s.', $db));
        }

        return $db < -20 ? (int) round(($db + 60) * 2) : (int) round(($db + 20) * 10) + 80;
    }

    /**
     * Frequency index 0..300 in 1/30 octave steps: 19.6875 Hz · 2^(i/30), 300 = 20.16 kHz.
     */
    public static function indexToHz(int $index): float
    {
        return self::FREQ_BASE * 2 ** ($index / 30);
    }

    public static function hzToIndex(float $hz): int
    {
        $index = (int) round(30 * log(max($hz, self::FREQ_BASE) / self::FREQ_BASE, 2));

        return max(0, min(self::FREQ_STEPS, $index));
    }

    /**
     * Display as in the vendor editor: "40.3Hz", "2.00KHz".
     */
    public static function formatHz(float $hz): string
    {
        return $hz >= 999.95 ? \sprintf('%.2fKHz', $hz / 1000) : \sprintf('%.1fHz', $hz);
    }

    /**
     * PEQ gain ±12 dB in 0.1 dB steps, 120 = 0 dB.
     */
    public static function peqGainToDb(int $raw): float
    {
        return round(($raw - self::PEQ_GAIN_UNITY) / 10, 1);
    }

    public static function dbToPeqGain(float $db): int
    {
        if ($db < -12 || $db > 12) {
            throw new \OutOfRangeException(\sprintf('PEQ gain must be -12..+12 dB, got %s.', $db));
        }

        return (int) round($db * 10) + self::PEQ_GAIN_UNITY;
    }

    /**
     * Q index 0..100: 0.4 · 320^(i/100), 25 = 1.70, 32 = 2.50.
     */
    public static function indexToQ(int $index): float
    {
        return 0.4 * 320 ** ($index / 100);
    }

    public static function qToIndex(float $q): int
    {
        if ($q <= 0.4) {
            return 0;
        }

        return max(0, min(self::Q_STEPS, (int) round(100 * log($q / 0.4) / log(320))));
    }

    /**
     * Gate, compressor and AutoMix threshold: raw/2 - 90 dB (180 = 0 dB, 220 = +20 dB).
     */
    public static function thresholdToDb(int $raw): float
    {
        return $raw / 2 - 90;
    }

    public static function dbToThreshold(float $db): int
    {
        return max(0, (int) round(($db + 90) * 2));
    }

    /**
     * Attack and release times: ms = raw + 1.
     */
    public static function rawToMs(int $raw): int
    {
        return $raw + 1;
    }

    public static function msToRaw(int $ms): int
    {
        return max(0, $ms - 1);
    }

    /**
     * Delay in samples at 48 kHz.
     */
    public static function samplesToMs(int $samples): float
    {
        return $samples * 1000 / self::SAMPLE_RATE;
    }

    public static function msToSamples(float $ms): int
    {
        return (int) round($ms * self::SAMPLE_RATE / 1000);
    }

    /**
     * Delay as the editor shows it, in the unit of command 0x15 (0 ms, 1 m, 2 ft).
     */
    public static function delayLabel(int $samples, int $unit = 0): string
    {
        $ms = self::samplesToMs($samples);

        return match ($unit) {
            1 => \sprintf('%.3f m', $ms / 1000 * self::SPEED_OF_SOUND),
            2 => \sprintf('%.3f ft', $ms / 1000 * self::SPEED_OF_SOUND * self::FEET_PER_METRE),
            default => \sprintf('%.3f ms', $ms),
        };
    }

    /**
     * Reverb time, approximated from one data point (139 = 1.96 s in the editor).
     */
    public static function reverbTimeSeconds(int $raw): float
    {
        return $raw * 1.96 / 139;
    }

    /**
     * Meter value in dB: 128 units per doubling of the level (21.26 per dB), and 0 dB at 15906, so that a 1 kHz
     * sine reads the threshold at which a limiter holds it. Measured from +12 to -50 dB over a loopback, see
     * docs/measurements.md. Digital silence (gain raw 0, or a channel fed only by muted inputs) reads about 0x05AD,
     * which lands on the floor like anything below it. A channel's own mute acts after its meter.
     */
    public static function meterDb(int $value): float
    {
        return max(self::METER_FLOOR, round(($value - self::METER_ZERO) * 20 * log10(2) / self::METER_PER_DOUBLING, 2));
    }

    /**
     * Inverse of meterDb() above the floor.
     */
    public static function dbToMeter(float $db): int
    {
        return (int) round(self::METER_ZERO + $db * self::METER_PER_DOUBLING / (20 * log10(2)));
    }
}
