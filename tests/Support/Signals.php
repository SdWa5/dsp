<?php

namespace App\Tests\Support;

use App\AutoEq\FittedBand;
use App\Filter\Biquad;
use App\Filter\Section;
use App\Measurement\Fft;
use App\Protocol\Scale;

/**
 * Test signals: seeded pink noise, sines, filters in the time and the frequency domain, delays and WAV encoding.
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
        return self::biquad($samples, $band->biquad());
    }

    /**
     * Runs the samples through a biquad in the time domain.
     *
     * @param list<float> $samples
     *
     * @return list<float>
     */
    public static function biquad(array $samples, Biquad $filter): array
    {
        [$b0, $b1, $b2, $a1, $a2] = [$filter->b0, $filter->b1, $filter->b2, $filter->a1, $filter->a2];
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
     * Runs the samples through any filter by its frequency response, in one FFT with room for the filter's
     * tail. This is how the fake recorder plays an output's filters, crossovers included.
     *
     * @param list<float> $samples
     *
     * @return list<float>
     */
    public static function filter(array $samples, Section $filter, int $rate = Scale::SAMPLE_RATE): array
    {
        $count = \count($samples);
        $size = 2 ** (int) ceil(log($count + $rate / 4, 2));
        $fft = new Fft($size);
        [$re, $im] = $fft->transform(array_pad($samples, $size, 0.0));
        for ($k = 0; $k <= $size / 2; ++$k) {
            [$hr, $hi] = $filter->at($k * $rate / $size);
            [$re[$k], $im[$k]] = [$re[$k] * $hr - $im[$k] * $hi, $re[$k] * $hi + $im[$k] * $hr];
            if ($k > 0 && $k < $size / 2) {
                // A real filter has the conjugate response at the negative frequencies
                [$re[$size - $k], $im[$size - $k]] = [$re[$k], -$im[$k]];
            }
        }
        // The bin at half the rate is its own mirror and stays real
        $im[$size / 2] = 0.0;

        return \array_slice($fft->inverse(array_values($re), array_values($im))[0], 0, $count);
    }

    /**
     * The samples delayed by $samples, which may be fractional or negative (an advance). A whole delay shifts,
     * a fractional one goes through a Blackman-windowed sinc of 2·TAPS + 1 taps. Samples from outside the
     * input read as silence, and the length stays the same.
     *
     * @param list<float> $samples
     *
     * @return list<float>
     */
    public static function delay(array $samples, float $delay): array
    {
        $count = \count($samples);
        $whole = (int) floor($delay);
        $fraction = $delay - $whole;
        if (abs($fraction) < 1e-12) {
            $out = [];
            for ($n = 0; $n < $count; ++$n) {
                $out[] = $samples[$n - $whole] ?? 0.0;
            }

            return $out;
        }
        $taps = 32;
        $kernel = [];
        for ($k = -$taps; $k <= $taps; ++$k) {
            // y[n] = Σ x[n - whole - k] · h[k], h[k] = sinc(k - fraction) in a Blackman window centred on the fraction
            $t = $k - $fraction;
            $sinc = sin(M_PI * $t) / (M_PI * $t);
            $phase = ($t + $taps + 1) / (2 * $taps + 2);
            $kernel[$k] = $sinc * (0.42 - 0.5 * cos(2 * M_PI * $phase) + 0.08 * cos(4 * M_PI * $phase));
        }
        $out = [];
        for ($n = 0; $n < $count; ++$n) {
            $sum = 0.0;
            foreach ($kernel as $k => $h) {
                $sum += ($samples[$n - $whole - $k] ?? 0.0) * $h;
            }
            $out[] = $sum;
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
