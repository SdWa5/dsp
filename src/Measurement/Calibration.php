<?php

namespace App\Measurement;

/**
 * Microphone calibration file as REW and the mic vendors write it: one "frequency dB [phase]" pair per
 * line. Header lines such as "Sens Factor =..." and comments are skipped.
 */
final class Calibration
{
    public static function read(string $path): Response
    {
        $text = @file_get_contents($path);
        if (false === $text) {
            throw new \InvalidArgumentException(\sprintf('Cannot read the calibration file "%s".', $path));
        }

        return self::parse($text);
    }

    public static function parse(string $text): Response
    {
        $points = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?)[\s,;]+([-+]?[0-9]+(?:\.[0-9]+)?)/', $line, $m) && (float) $m[1] > 0) {
                $points[(string) (float) $m[1]] = (float) $m[2];
            }
        }
        if (\count($points) < 2) {
            throw new \InvalidArgumentException('The calibration file has fewer than two "frequency dB" lines.');
        }
        ksort($points, \SORT_NUMERIC);

        return new Response(array_map('floatval', array_keys($points)), array_values($points));
    }
}
