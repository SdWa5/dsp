<?php

namespace App\Measurement;

/**
 * Iterative radix-2 FFT on plain float arrays.
 *
 * Written here rather than pulled in: the PHP FFT packages on Packagist either carry no license or
 * wrap every sample in an object, which is too slow for a few hundred thousand samples on the VIM3.
 */
final class Fft
{
    private readonly int $bits;
    /** @var list<int> */
    private readonly array $reverse;
    /** @var list<float> */
    private readonly array $cos;
    /** @var list<float> */
    private readonly array $sin;

    public function __construct(public readonly int $size)
    {
        if ($size < 2 || ($size & ($size - 1)) !== 0) {
            throw new \InvalidArgumentException(\sprintf('The FFT size must be a power of two, got %d.', $size));
        }
        $this->bits = (int) round(log($size, 2));
        $reverse = [];
        for ($i = 0; $i < $size; ++$i) {
            $r = 0;
            for ($b = 0, $v = $i; $b < $this->bits; ++$b, $v >>= 1) {
                $r = ($r << 1) | ($v & 1);
            }
            $reverse[] = $r;
        }
        $this->reverse = $reverse;
        $cos = $sin = [];
        for ($i = 0; $i < $size / 2; ++$i) {
            $cos[] = cos(2 * M_PI * $i / $size);
            $sin[] = -sin(2 * M_PI * $i / $size);
        }
        $this->cos = $cos;
        $this->sin = $sin;
    }

    /**
     * Power |X[k]|² of the bins 0..size/2 of a real signal of exactly $size samples.
     *
     * @param list<float> $samples
     *
     * @return list<float>
     */
    public function power(array $samples): array
    {
        if (\count($samples) !== $this->size) {
            throw new \LengthException(\sprintf('The FFT needs %d samples, got %d.', $this->size, \count($samples)));
        }
        $re = [];
        foreach ($this->reverse as $r) {
            $re[] = $samples[$r];
        }
        $im = array_fill(0, $this->size, 0.0);
        for ($half = 1, $step = $this->size >> 1; $half < $this->size; $half <<= 1, $step >>= 1) {
            for ($start = 0; $start < $this->size; $start += $half << 1) {
                for ($k = 0, $t = 0; $k < $half; ++$k, $t += $step) {
                    $a = $start + $k;
                    $b = $a + $half;
                    $wr = $this->cos[$t];
                    $wi = $this->sin[$t];
                    $xr = $re[$b] * $wr - $im[$b] * $wi;
                    $xi = $re[$b] * $wi + $im[$b] * $wr;
                    $re[$b] = $re[$a] - $xr;
                    $im[$b] = $im[$a] - $xi;
                    $re[$a] += $xr;
                    $im[$a] += $xi;
                }
            }
        }
        $power = [];
        for ($k = 0; $k <= $this->size >> 1; ++$k) {
            $power[] = $re[$k] * $re[$k] + $im[$k] * $im[$k];
        }

        return $power;
    }
}
