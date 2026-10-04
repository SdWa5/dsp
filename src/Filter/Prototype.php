<?php

namespace App\Filter;

/**
 * Poles of the analog low pass prototypes behind the HPF and LPF slopes, with the corner at 1 rad/s.
 *
 * Butterworth (BW) is maximally flat and -3 dB at the corner. Bessel (BL) has the flattest group delay, and its
 * corner depends on a BesselNorm. Linkwitz-Riley (LK) is a Butterworth of half the order applied twice, which
 * is -6 dB at the corner. The low pass is H(s) = ∏ -p / (s - p), which is 1 at 0 Hz.
 */
final class Prototype
{
    /** @var array<string, list<array{float, float}>> */
    private static array $cache = [];

    /**
     * @param 'BW'|'BL'|'LK' $family
     *
     * @return list<array{float, float}>
     */
    public static function poles(string $family, int $order, BesselNorm $norm = BesselNorm::DEFAULT): array
    {
        if ($order < 1 || $order > 8 || ('LK' === $family && 1 === $order % 2)) {
            throw new \OutOfRangeException(\sprintf('There is no %s filter of order %d.', $family, $order));
        }

        return self::$cache["$family$order{$norm->value}"] ??= match ($family) {
            'BW' => self::butterworth($order),
            'BL' => self::bessel($order, $norm),
            'LK' => [...self::butterworth(intdiv($order, 2)), ...self::butterworth(intdiv($order, 2))],
        };
    }

    /**
     * Complex gain of the low pass with these poles at the complex frequency $s.
     *
     * @param list<array{float, float}> $poles
     * @param array{float, float}       $s
     *
     * @return array{float, float}
     */
    public static function lowPass(array $poles, array $s): array
    {
        $h = [1.0, 0.0];
        foreach ($poles as [$re, $im]) {
            $h = Complex::mul($h, Complex::div([-$re, -$im], [$s[0] - $re, $s[1] - $im]));
        }

        return $h;
    }

    /**
     * @return list<array{float, float}>
     */
    private static function butterworth(int $order): array
    {
        $poles = [];
        for ($k = 1; $k <= $order; ++$k) {
            $angle = M_PI * (2 * $k + $order - 1) / (2 * $order);
            $poles[] = [cos($angle), sin($angle)];
        }

        return $poles;
    }

    /**
     * Roots of the reverse Bessel polynomial, which puts the group delay at 0 Hz to 1 s, scaled to the norm.
     *
     * @return list<array{float, float}>
     */
    private static function bessel(int $order, BesselNorm $norm): array
    {
        // θn(s) = Σ ak·s^k with ak = (2n-k)! / (2^(n-k)·k!·(n-k)!), which is monic
        $coefficients = [];
        for ($k = 0; $k <= $order; ++$k) {
            $coefficients[] = self::factorial(2 * $order - $k) / (2 ** ($order - $k) * self::factorial($k) * self::factorial($order - $k));
        }
        $poles = self::roots($coefficients);
        $scale = match ($norm) {
            BesselNorm::Delay => 1.0,
            BesselNorm::Phase => $coefficients[0] ** (1 / $order),
            BesselNorm::Magnitude => self::corner($poles),
        };

        return array_map(static fn (array $p): array => [$p[0] / $scale, $p[1] / $scale], $poles);
    }

    /**
     * The angular frequency where the low pass with these poles falls to -3 dB, by bisection.
     *
     * @param list<array{float, float}> $poles
     */
    private static function corner(array $poles): float
    {
        [$low, $high] = [0.0, 1.0];
        while (Complex::db(self::lowPass($poles, [0.0, $high])) > -10 * log10(2)) {
            $high *= 2;
        }
        for ($i = 0; $i < 60; ++$i) {
            $mid = ($low + $high) / 2;
            if (Complex::db(self::lowPass($poles, [0.0, $mid])) > -10 * log10(2)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ($low + $high) / 2;
    }

    /**
     * Roots of a monic polynomial by the Durand-Kerner iteration, which converges for the small orders here.
     *
     * @param list<float> $coefficients a0 up to an = 1
     *
     * @return list<array{float, float}>
     */
    private static function roots(array $coefficients): array
    {
        $order = \count($coefficients) - 1;
        $radius = abs($coefficients[0]) ** (1 / $order);
        $roots = [];
        $seed = [1.0, 0.0];
        for ($i = 0; $i < $order; ++$i) {
            $seed = Complex::mul($seed, [0.4, 0.9]);
            $roots[] = [$radius * $seed[0], $radius * $seed[1]];
        }
        for ($iteration = 0; $iteration < 500; ++$iteration) {
            $moved = 0.0;
            foreach ($roots as $i => $z) {
                $value = [1.0, 0.0];
                for ($k = $order - 1; $k >= 0; --$k) {
                    $value = Complex::mul($value, $z);
                    $value[0] += $coefficients[$k];
                }
                $product = [1.0, 0.0];
                foreach ($roots as $j => $other) {
                    if ($i !== $j) {
                        $product = Complex::mul($product, [$z[0] - $other[0], $z[1] - $other[1]]);
                    }
                }
                $step = Complex::div($value, $product);
                $roots[$i] = [$z[0] - $step[0], $z[1] - $step[1]];
                $moved = max($moved, hypot($step[0], $step[1]));
            }
            if ($moved < 1e-14 * $radius) {
                break;
            }
        }

        return $roots;
    }

    private static function factorial(int $n): float
    {
        return $n < 2 ? 1.0 : $n * self::factorial($n - 1);
    }
}
