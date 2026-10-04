<?php

namespace App\Filter;

use App\Protocol\Labels;
use App\Protocol\Scale;

/**
 * A second-order digital filter, normalised to a0 = 1, and the PEQ types built as biquads.
 *
 * Every PEQ type is an analog prototype with s normalised to the band's frequency, taken through the bilinear
 * transform with that frequency prewarped, at the device's 48 kHz. The loopback walk of 2026-09-30 fits the
 * 8x8 within 0.12 dB and 0.6° this way (docs/protocol.md).
 *
 * - A boost puts its poles at the band's frequency with the band's Q, and a cut is the exact inverse of the
 *   boost of the same size. A peak of G dB therefore equals the RBJ peak with Q · 10^(-|G|/40), which is Q/1.41
 *   at ±6 dB. A shelf has its poles, for a cut its zeros, at the band's frequency on the side of its gain. Its
 *   half gain lies 10^(|G|/80) times further towards the flat side, where the RBJ shelf puts its frequency.
 * - The low pass, the high pass and Allpass2 are the RBJ cookbook filters, and they ignore the gain. Allpass1 is
 *   the first-order allpass with its -90° at the band's frequency, and it ignores the Q as well.
 */
final readonly class Biquad implements Section
{
    public function __construct(
        public float $b0,
        public float $b1,
        public float $b2,
        public float $a1,
        public float $a2,
        public int $rate = Scale::SAMPLE_RATE,
    ) {
    }

    /**
     * A PEQ band of type $type (index into Labels::PEQ_TYPES).
     */
    public static function peq(int $type, float $hz, float $db, float $q, int $rate = Scale::SAMPLE_RATE): self
    {
        if (!isset(Labels::PEQ_TYPES[$type])) {
            throw new \OutOfRangeException(\sprintf('PEQ type %d is not one of 0..%d.', $type, \count(Labels::PEQ_TYPES) - 1));
        }
        $g = 10 ** (abs($db) / 20);
        $poles = [1.0, 1 / $q, 1.0];

        return match ($type) {
            0 => self::boost([1.0, $g / $q, 1.0], $poles, $db, $hz, $rate),
            1 => self::boost([$g, sqrt($g) / $q, 1.0], $poles, $db, $hz, $rate),
            2 => self::boost([1.0, sqrt($g) / $q, $g], $poles, $db, $hz, $rate),
            3 => self::bilinear([1.0, 0.0, 0.0], $poles, $hz, $rate),
            4 => self::bilinear([0.0, 0.0, 1.0], $poles, $hz, $rate),
            5 => self::bilinear([1.0, -1.0, 0.0], [1.0, 1.0, 0.0], $hz, $rate),
            default => self::bilinear([1.0, -1 / $q, 1.0], $poles, $hz, $rate),
        };
    }

    public function at(float $hz): array
    {
        $w = 2 * M_PI * $hz / $this->rate;
        [$c1, $s1, $c2, $s2] = [cos($w), sin($w), cos(2 * $w), sin(2 * $w)];

        // H(e^jw) = (b0 + b1·e^-jw + b2·e^-2jw) / (1 + a1·e^-jw + a2·e^-2jw)
        return Complex::div(
            [$this->b0 + $this->b1 * $c1 + $this->b2 * $c2, -($this->b1 * $s1 + $this->b2 * $s2)],
            [1 + $this->a1 * $c1 + $this->a2 * $c2, -($this->a1 * $s1 + $this->a2 * $s2)],
        );
    }

    public function db(float $hz): float
    {
        return Complex::db($this->at($hz));
    }

    public function degrees(float $hz): float
    {
        return Complex::degrees($this->at($hz));
    }

    /**
     * @param array{float, float, float} $boost the boost's numerator
     * @param array{float, float, float} $poles
     */
    private static function boost(array $boost, array $poles, float $db, float $hz, int $rate): self
    {
        return $db >= 0 ? self::bilinear($boost, $poles, $hz, $rate) : self::bilinear($poles, $boost, $hz, $rate);
    }

    /**
     * The analog prototype (b[0] + b[1]·s + b[2]·s²) / (a[0] + a[1]·s + a[2]·s²) through the bilinear transform
     * s = (1 - z^-1) / (k · (1 + z^-1)) with k = tan(π · $hz / $rate), which maps s = j to $hz. A first-order
     * prototype stays first order.
     *
     * @param array{float, float, float} $b
     * @param array{float, float, float} $a
     */
    private static function bilinear(array $b, array $a, float $hz, int $rate): self
    {
        $k = tan(M_PI * $hz / $rate);
        $z = 0.0 === $b[2] && 0.0 === $a[2]
            ? static fn (array $c): array => [$c[0] * $k + $c[1], $c[0] * $k - $c[1], 0.0]
            : static fn (array $c): array => [$c[0] * $k * $k + $c[1] * $k + $c[2], 2 * ($c[0] * $k * $k - $c[2]), $c[0] * $k * $k - $c[1] * $k + $c[2]];
        [$n, $d] = [$z($b), $z($a)];

        return new self($n[0] / $d[0], $n[1] / $d[0], $n[2] / $d[0], $d[1] / $d[0], $d[2] / $d[0], $rate);
    }
}
