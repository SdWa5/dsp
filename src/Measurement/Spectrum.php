<?php

namespace App\Measurement;

use App\Protocol\Labels;

/**
 * Fractional-octave level of a recording, the way a pink noise measurement is read.
 *
 * Welch's method: Hann-windowed segments with 50 % overlap, power averaged. analyse() gives every grid point
 * the mean bin power of its band times the band centre, so pink noise through a flat system reads flat.
 * bands() sums the bin power of every third octave instead, in dBFS.
 */
final class Spectrum
{
    private readonly Fft $fft;
    /** @var list<float> */
    private readonly array $window;
    private readonly float $windowPower;

    /**
     * bands() needs 65536 points, so that the 20 Hz band gets six bins at 48 kHz.
     */
    public function __construct(int $size = 16384)
    {
        $this->fft = new Fft($size);
        $window = [];
        $power = 0.0;
        for ($i = 0; $i < $size; ++$i) {
            $window[] = $w = 0.5 - 0.5 * cos(2 * M_PI * $i / ($size - 1));
            $power += $w * $w;
        }
        $this->window = $window;
        $this->windowPower = $power;
    }

    /**
     * @param list<float> $samples
     * @param list<float> $freqs   grid, see Response::grid()
     * @param float       $octaves band width around every grid point
     */
    public function analyse(array $samples, int $rate, array $freqs, float $octaves = 1 / 6): Response
    {
        $size = $this->fft->size;
        [$bins, $segments] = $this->welch($samples);
        $resolution = $rate / $size;
        $half = 2 ** ($octaves / 2);
        $db = [];
        foreach ($freqs as $f) {
            $first = max(1, (int) ceil($f / $half / $resolution));
            $last = min(\count($bins) - 1, (int) floor($f * $half / $resolution));
            if ($last < $first) {
                $first = $last = max(1, min(\count($bins) - 1, (int) round($f / $resolution)));
            }
            $sum = 0.0;
            for ($k = $first; $k <= $last; ++$k) {
                $sum += $bins[$k];
            }
            $db[] = 10 * log10(max($sum / ($last - $first + 1) / $segments * $f, 1e-30));
        }

        return new Response($freqs, $db);
    }

    /**
     * Third-octave levels in dBFS on the scale of Levels, one per test tone frequency from 20 Hz to 20 kHz.
     *
     * The bands meet at 10^(±1/20) around their centres, and their powers add up to the mean square of the
     * recording as far as it lies between 17.8 Hz and 22.4 kHz. A sine of amplitude A reads 20·log10(A/√2)
     * in its band. Silence reads Levels::FLOOR.
     *
     * @param list<float> $samples
     *
     * @return list<array{hz: float, label: string, db: float}>
     */
    public function bands(array $samples, int $rate): array
    {
        $size = $this->fft->size;
        [$bins, $segments] = $this->welch($samples);
        $resolution = $rate / $size;
        // One-sided bins count twice, except DC and Nyquist. Parseval and the window's power give the mean square.
        $scale = 1 / ($size * $this->windowPower * $segments);
        $edge = 10 ** (1 / 20);
        $bands = [];
        foreach (Labels::TONE_FREQUENCIES as $i => $label) {
            $hz = TestTone::frequency($i);
            $first = max(0, (int) ceil($hz / $edge / $resolution));
            $last = min(\count($bins) - 1, (int) ceil($hz * $edge / $resolution) - 1);
            $sum = 0.0;
            for ($k = $first; $k <= $last; ++$k) {
                $sum += (0 === $k || $size >> 1 === $k ? 1 : 2) * $bins[$k];
            }
            $bands[] = ['hz' => $hz, 'label' => $label, 'db' => max(Levels::FLOOR, 10 * log10(max($sum * $scale, 1e-30)))];
        }

        return $bands;
    }

    /**
     * Power per bin 0..size/2 summed over the segments, and the number of segments.
     *
     * @param list<float> $samples
     *
     * @return array{array<int, float>, int}
     */
    private function welch(array $samples): array
    {
        $size = $this->fft->size;
        if (\count($samples) < $size) {
            throw new \LengthException(\sprintf('The recording needs at least %d samples, got %d.', $size, \count($samples)));
        }
        $bins = array_fill(0, ($size >> 1) + 1, 0.0);
        $segments = 0;
        for ($start = 0; $start + $size <= \count($samples); $start += $size >> 1) {
            $segment = [];
            for ($i = 0; $i < $size; ++$i) {
                $segment[] = $samples[$start + $i] * $this->window[$i];
            }
            foreach ($this->fft->power($segment) as $k => $p) {
                $bins[$k] += $p;
            }
            ++$segments;
        }

        return [$bins, $segments];
    }
}
