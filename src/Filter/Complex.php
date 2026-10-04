<?php

namespace App\Filter;

/**
 * Complex numbers as [real, imaginary] pairs, which keeps the response loops free of objects.
 */
final class Complex
{
    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     *
     * @return array{float, float}
     */
    public static function mul(array $a, array $b): array
    {
        return [$a[0] * $b[0] - $a[1] * $b[1], $a[0] * $b[1] + $a[1] * $b[0]];
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     *
     * @return array{float, float}
     */
    public static function div(array $a, array $b): array
    {
        $d = $b[0] * $b[0] + $b[1] * $b[1];

        return [($a[0] * $b[0] + $a[1] * $b[1]) / $d, ($a[1] * $b[0] - $a[0] * $b[1]) / $d];
    }

    /**
     * Magnitude in dB, with a floor of -300 dB for a zero.
     *
     * @param array{float, float} $z
     */
    public static function db(array $z): float
    {
        return 10 * log10(max($z[0] * $z[0] + $z[1] * $z[1], 1e-30));
    }

    /**
     * Phase in degrees, -180..180.
     *
     * @param array{float, float} $z
     */
    public static function degrees(array $z): float
    {
        return rad2deg(atan2($z[1], $z[0]));
    }
}
