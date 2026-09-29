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
    /** @var list<float> */
    private readonly array $sinInverse;

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
        $this->sinInverse = array_map(static fn (float $v): float => -$v, $sin);
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
        [$re, $im] = $this->transform($samples);
        $power = [];
        for ($k = 0; $k <= $this->size >> 1; ++$k) {
            $power[] = $re[$k] * $re[$k] + $im[$k] * $im[$k];
        }

        return $power;
    }

    /**
     * Complex transform X[k] = Σ x[n]·e^(-2πikn/N) of exactly $size samples. Without $imag the input is real.
     *
     * @param list<float>      $real
     * @param list<float>|null $imag
     *
     * @return array{list<float>, list<float>} real and imaginary part of the bins 0..size-1
     */
    public function transform(array $real, ?array $imag = null): array
    {
        return $this->run($real, $imag, false);
    }

    /**
     * Inverse transform x[n] = 1/N · Σ X[k]·e^(2πikn/N), so that inverse(transform(x)) gives x back.
     *
     * @param list<float> $real
     * @param list<float> $imag
     *
     * @return array{list<float>, list<float>}
     */
    public function inverse(array $real, array $imag): array
    {
        [$re, $im] = $this->run($real, $imag, true);
        $scale = 1 / $this->size;

        return [array_map(static fn (float $v): float => $v * $scale, $re), array_map(static fn (float $v): float => $v * $scale, $im)];
    }

    /**
     * @param list<float>      $real
     * @param list<float>|null $imag
     *
     * @return array{list<float>, list<float>}
     */
    private function run(array $real, ?array $imag, bool $inverse): array
    {
        if (\count($real) !== $this->size || (null !== $imag && \count($imag) !== $this->size)) {
            throw new \LengthException(\sprintf('The FFT needs %d samples, got %d.', $this->size, \count($real)));
        }
        $re = [];
        foreach ($this->reverse as $r) {
            $re[] = $real[$r];
        }
        if (null === $imag) {
            $im = array_fill(0, $this->size, 0.0);
        } else {
            $im = [];
            foreach ($this->reverse as $r) {
                $im[] = $imag[$r];
            }
        }
        // Locals instead of properties, the loop runs size/2 · log2(size) times
        $cos = $this->cos;
        $sin = $inverse ? $this->sinInverse : $this->sin;
        $size = $this->size;
        for ($half = 1, $step = $size >> 1; $half < $size; $half <<= 1, $step >>= 1) {
            for ($start = 0; $start < $size; $start += $half << 1) {
                for ($k = 0, $t = 0; $k < $half; ++$k, $t += $step) {
                    $a = $start + $k;
                    $b = $a + $half;
                    $wr = $cos[$t];
                    $wi = $sin[$t];
                    $br = $re[$b];
                    $bi = $im[$b];
                    $xr = $br * $wr - $bi * $wi;
                    $xi = $br * $wi + $bi * $wr;
                    $ar = $re[$a];
                    $ai = $im[$a];
                    $re[$b] = $ar - $xr;
                    $im[$b] = $ai - $xi;
                    $re[$a] = $ar + $xr;
                    $im[$a] = $ai + $xi;
                }
            }
        }

        return [array_values($re), array_values($im)];
    }
}
