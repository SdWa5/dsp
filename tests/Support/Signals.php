<?php

namespace App\Tests\Support;

use App\AutoEq\FittedBand;
use App\Protocol\Scale;

/**
 * Test signals: seeded pink noise, sines, a peaking filter in the time domain and WAV encoding.
 */
final class Signals
{
    /**
     * Pink noise after Paul Kellet's refined filter, peak about 0.5.
     *
     * @return list<float>
     */
    public static function pinkNoise(int $count, int $seed = 1): array
    {
        mt_srand($seed);
        [$b0, $b1, $b2, $b3, $b4, $b5, $b6] = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        $samples = [];
        for ($i = 0; $i < $count; ++$i) {
            $white = mt_rand() / mt_getrandmax() * 2 - 1;
            $b0 = 0.99886 * $b0 + $white * 0.0555179;
            $b1 = 0.99332 * $b1 + $white * 0.0750759;
            $b2 = 0.96900 * $b2 + $white * 0.1538520;
            $b3 = 0.86650 * $b3 + $white * 0.3104856;
            $b4 = 0.55000 * $b4 + $white * 0.5329522;
            $b5 = -0.7616 * $b5 - $white * 0.0168980;
            $samples[] = ($b0 + $b1 + $b2 + $b3 + $b4 + $b5 + $b6 + $white * 0.5362) * 0.08;
            $b6 = $white * 0.115926;
        }

        return $samples;
    }

    /**
     * @return list<float>
     */
    public static function sine(float $hz, int $count, float $amplitude = 0.5): array
    {
        $samples = [];
        for ($i = 0; $i < $count; ++$i) {
            $samples[] = $amplitude * sin(2 * M_PI * $hz * $i / Scale::SAMPLE_RATE);
        }

        return $samples;
    }

    /**
     * Runs the samples through the RBJ peaking filter of $band (the same shape FittedBand models).
     *
     * @param list<float> $samples
     *
     * @return list<float>
     */
    public static function peak(array $samples, FittedBand $band): array
    {
        $a = 10 ** ($band->db() / 40);
        $w0 = 2 * M_PI * $band->hz() / Scale::SAMPLE_RATE;
        $alpha = sin($w0) / (2 * $band->qValue());
        $a0 = 1 + $alpha / $a;
        [$b0, $b1, $b2] = [(1 + $alpha * $a) / $a0, -2 * cos($w0) / $a0, (1 - $alpha * $a) / $a0];
        [$a1, $a2] = [-2 * cos($w0) / $a0, (1 - $alpha / $a) / $a0];
        [$x1, $x2, $y1, $y2] = [0.0, 0.0, 0.0, 0.0];
        $out = [];
        foreach ($samples as $x) {
            $y = $b0 * $x + $b1 * $x1 + $b2 * $x2 - $a1 * $y1 - $a2 * $y2;
            [$x2, $x1, $y2, $y1] = [$x1, $x, $y1, $y];
            $out[] = $y;
        }

        return $out;
    }

    /**
     * @param list<list<float>> $channels equal length, one list per channel
     */
    public static function wav(array $channels, int $bits = 16, bool $float = false, int $rate = Scale::SAMPLE_RATE): string
    {
        $width = intdiv($bits, 8);
        $data = '';
        foreach (array_keys($channels[0]) as $i) {
            foreach ($channels as $channel) {
                $v = max(-1.0, min(1.0, $channel[$i]));
                $data .= match (true) {
                    $float => pack('g', $v),
                    16 === $bits => pack('v', (int) round($v * 32767) & 0xFFFF),
                    24 === $bits => substr(pack('V', (int) round($v * 8388607) & 0xFFFFFF), 0, 3),
                    default => pack('V', (int) round($v * 2147483647) & 0xFFFFFFFF),
                };
            }
        }
        $fmt = pack('vvVVvv', $float ? 3 : 1, \count($channels), $rate, $rate * $width * \count($channels), $width * \count($channels), $bits);

        return 'RIFF'.pack('V', 4 + 8 + \strlen($fmt) + 8 + \strlen($data)).'WAVEfmt '.pack('V', \strlen($fmt)).$fmt.'data'.pack('V', \strlen($data)).$data;
    }
}
