<?php

namespace App\Measurement;

/**
 * Fractional-octave level of a recording, the way a pink noise measurement is read.
 *
 * Welch's method: Hann-windowed segments with 50 % overlap, power averaged. Every grid point gets the
 * mean bin power of its band times the band centre, so pink noise through a flat system reads flat.
 */
final class Spectrum
{
    private readonly Fft $fft;
    /** @var list<float> */
    private readonly array $window;

    public function __construct(int $size = 16384)
    {
        $this->fft = new Fft($size);
        $window = [];
        for ($i = 0; $i < $size; ++$i) {
            $window[] = 0.5 - 0.5 * cos(2 * M_PI * $i / ($size - 1));
        }
        $this->window = $window;
    }

    /**
     * @param list<float> $samples
     * @param list<float> $freqs   grid, see Response::grid()
     * @param float       $octaves band width around every grid point
     */
    public function analyse(array $samples, int $rate, array $freqs, float $octaves = 1 / 6): Response
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
}
